<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\BasePrice;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\TariffRate;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pricing: the platform cost matrix and the per-tariff selling rates.
 *
 * Commission and charge are percentages of the requested amount. A commission
 * larger than 100% would pay a reseller to spend money, so it is capped in
 * validation - the legacy form accepted any number.
 */
class TariffController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.tariffs.index', [
            'tariffs' => Tariff::query()
                ->orderBy('name')
                ->paginate((int) config('telecom.ui.per_page', 25)),
        ]);
    }

    /** The platform-wide cost matrix. */
    public function prices(Request $request): View
    {
        $serviceId = $request->integer('service') ?: null;

        return view('admin.tariffs.prices', [
            'services' => Service::query()->orderBy('title')->get(['serviceid', 'title']),
            'selected' => $serviceId,
            'prices'   => BasePrice::query()
                ->when($serviceId, fn ($q) => $q->where('service', $serviceId))
                ->orderBy('service')
                ->orderBy('prefix')
                ->get(),
        ]);
    }

    public function updatePrices(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'prices'            => ['required', 'array'],
            'prices.*.id'       => ['required', 'integer', 'exists:price,id'],
            'prices.*.rate'     => ['required', 'numeric', 'min:0', 'max:10'],
            'prices.*.comm'     => ['required', 'numeric', 'min:0', 'max:100'],
            'prices.*.charge'   => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'prices.*.comm'   => 'commission',
            'prices.*.charge' => 'charge',
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['prices'] as $row) {
                BasePrice::query()->where('id', $row['id'])->update([
                    'rate'   => $row['rate'],
                    'comm'   => $row['comm'],
                    'charge' => $row['charge'],
                ]);
            }
        });

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'pricing.base_updated',
            sprintf('Updated %d base price row(s)', count($validated['prices'])),
        );

        return back()->with('status', 'Base prices saved.');
    }

    public function show(Request $request, Tariff $tariff): View
    {
        return view('admin.tariffs.show', [
            'tariff' => $tariff,
            'rates'  => TariffRate::query()
                ->where('tarif', $tariff->id)
                ->orderBy('service')
                ->orderBy('pcode')
                ->get(),
            'services' => Service::query()->orderBy('title')->get(['serviceid', 'title']),
        ]);
    }

    public function update(Request $request, Tariff $tariff): RedirectResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:120'],
            'desc'            => ['nullable', 'string', 'max:500'],
            'rates'           => ['array'],
            'rates.*.id'      => ['required', 'integer', 'exists:rate_module,id'],
            'rates.*.comm'    => ['required', 'numeric', 'min:0', 'max:100'],
            'rates.*.charge'  => ['required', 'numeric', 'min:0', 'max:100'],
            'rates.*.status'  => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($tariff, $validated): void {
            $tariff->forceFill([
                'name' => $validated['name'],
                'desc' => $validated['desc'] ?? '',
            ])->save();

            foreach ($validated['rates'] ?? [] as $row) {
                TariffRate::query()
                    ->where('id', $row['id'])
                    // Scope the update to this tariff so a tampered id cannot
                    // reprice somebody else's rate row.
                    ->where('tarif', $tariff->id)
                    ->update([
                        'comm'   => $row['comm'],
                        'charge' => $row['charge'],
                        'status' => ! empty($row['status']) ? 1 : 0,
                    ]);
            }
        });

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'pricing.tariff_updated',
            sprintf('Updated tariff %s', $tariff->name),
            ['tariff_id' => (int) $tariff->id],
        );

        return back()->with('status', 'Tariff saved.');
    }
}
