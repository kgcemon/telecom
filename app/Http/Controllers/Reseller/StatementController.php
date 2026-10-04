<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A reseller's own wallet statement. */
class StatementController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);

        $query = LedgerEntry::query()
            ->where('userid', $user->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->betweenDates(
                $request->date('from')?->format('Y-m-d'),
                $request->date('to')?->format('Y-m-d'),
            );

        $totals = (clone $query)->getQuery()
            ->selectRaw('COALESCE(SUM(debit), 0) AS debits, COALESCE(SUM(credit), 0) AS credits')
            ->first();

        return view('reseller.statement.index', [
            'entries' => $query->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'user'    => $user,
            'symbol'  => $this->settings->currencySymbol(),
            'filters' => $request->only(['type', 'from', 'to']),
            'totals'  => [
                'debits'  => (float) ($totals->debits ?? 0),
                'credits' => (float) ($totals->credits ?? 0),
            ],
        ]);
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
