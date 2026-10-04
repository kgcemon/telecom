<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceType;
use App\Models\Reseller;
use App\Models\Service;
use App\Services\Recharge\PricingService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the signed-in reseller pays, per service and operator.
 *
 * Figures come from the same PricingService the engine uses, quoted at a
 * reference amount, so the rate card can never disagree with what is actually
 * charged.
 */
class RateController
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $reference = 100.0;

        $sampleNumbers = [
            'GP' => '01712345678',
            'RB' => '01812345678',
            'BL' => '01912345678',
            'AT' => '01612345678',
            'TT' => '01512345678',
            'SK' => '11312345678',
        ];

        $rows = [];

        foreach (Service::query()->enabled()->ordered()->get() as $service) {
            $type = $service->type();

            if ($type === null) {
                continue;
            }

            foreach ($sampleNumbers as $code => $number) {
                $quote = $this->pricing->quote(
                    reseller: $user,
                    service: $type,
                    amount: $reference,
                    number: $number,
                    numberType: '1',
                    declaredOperator: $code,
                );

                if (! $quote->resolved) {
                    continue;
                }

                $rows[$type->label()][$code] = [
                    'commission_percent' => $quote->commissionPercent,
                    'charge_percent'     => $quote->chargePercent,
                    'payable_on_100'     => $quote->payable(),
                ];
            }
        }

        return view('reseller.rates.index', [
            'rows'      => $rows,
            'operators' => array_keys($sampleNumbers),
            'reference' => $reference,
            'symbol'    => $this->settings->currencySymbol(),
            'user'      => $user,
        ]);
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
