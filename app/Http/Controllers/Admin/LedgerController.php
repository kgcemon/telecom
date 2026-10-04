<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wallet statement across the network.
 *
 * Paginated deliberately: the legacy transaction report selected every row in
 * the chosen range before rendering, which on a wide range simply ran out of
 * memory.
 */
class LedgerController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): View
    {
        $query = LedgerEntry::query()
            ->with('reseller:id,username,name')
            ->when($request->filled('reseller'), fn ($q) => $q->where('userid', $request->integer('reseller')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('q'), fn ($q) => $q->where('desc', 'like', '%'.$request->string('q')->toString().'%'))
            ->betweenDates(
                $request->date('from')?->format('Y-m-d'),
                $request->date('to')?->format('Y-m-d'),
            );

        $totals = (clone $query)->getQuery()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(debit), 0) AS debits, COALESCE(SUM(credit), 0) AS credits')
            ->first();

        return view('admin.ledger.index', [
            'entries' => $query->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'filters' => $request->only(['reseller', 'type', 'q', 'from', 'to']),
            'symbol'  => $this->settings->currencySymbol(),
            'totals'  => [
                'count'   => (int) ($totals->n ?? 0),
                'debits'  => (float) ($totals->debits ?? 0),
                'credits' => (float) ($totals->credits ?? 0),
            ],
            'resellerName' => $request->filled('reseller')
                ? Reseller::query()->whereKey($request->integer('reseller'))->value('username')
                : null,
        ]);
    }
}
