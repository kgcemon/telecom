<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Enums\ServiceType;
use App\Models\BasePrice;
use App\Models\DrivePackage;
use App\Models\FixedCommission;
use App\Models\InternetPackage;
use App\Models\Reseller;
use App\Models\TariffRate;
use App\Services\Recharge\Dto\PriceQuote;

/**
 * Works out what a request costs a given reseller.
 *
 * Resolution order, most specific first:
 *   1. `rate_module` - an override for this reseller's tariff
 *   2. `price`       - the platform-wide matrix for service + prefix + type
 *   3. a safe default of "charge the face value", so a missing price row can
 *      never be read as "free"
 *
 * Step 3 is deliberate. The legacy code left `$rate`, `$comm` and `$charge`
 * undefined when no price row matched, then computed `$amount * null`, which
 * PHP evaluates to 0. A top-up on an unpriced prefix therefore cost the
 * reseller nothing while still being dispatched - straightforwardly free
 * money. It patched over this afterwards with
 * `if (empty($final_amount)) $final_amount = $amount;`, but that only caught
 * the case where the total came to exactly zero, not a partial underprice.
 * Here an unresolved price is reported on the quote and the caller refuses the
 * request rather than guessing.
 */
class PricingService
{
    public function __construct(private readonly OperatorResolver $operators) {}

    /**
     * Quote a recharge.
     *
     * @param  string       $numberType  Legacy `type`: 1 = prepaid, 2 = postpaid,
     *                                   3/4 = wallet variants.
     * @param  int|null     $packageId   drive_package / net_package id when the
     *                                   request is for a fixed bundle.
     */
    public function quote(
        Reseller $reseller,
        ServiceType $service,
        float $amount,
        string $number,
        string $numberType = '1',
        ?string $declaredOperator = null,
        ?int $packageId = null,
    ): PriceQuote {
        $prefix = $this->operators->prefixOf($number);
        $operatorCode = $this->operators->resolve($number, $declaredOperator);

        $base = $this->findBasePrice($service, $prefix, $numberType, $operatorCode);
        $pcode = $base->pcode ?? $operatorCode;

        $override = $this->findTariffRate($reseller, $service, (string) $pcode);

        $source = match (true) {
            $override !== null => 'tariff:'.$reseller->tarif,
            $base !== null     => 'base-price',
            default            => 'face-value-fallback',
        };

        $rate = $this->firstNumeric([$override?->rate, $base?->rate], default: 1.0);
        $commissionPercent = $this->firstNumeric([$override?->comm, $base?->comm], default: 0.0);
        $chargePercent = $this->firstNumeric([$override?->charge, $base?->charge], default: 0.0);

        // A flat package commission replaces the percentage one rather than
        // stacking with it, matching how packages were always priced.
        [$fixedCommission, $fixedCharge] = $this->packageAdjustments(
            $service, $amount, $prefix, (string) $pcode, $packageId
        );

        if ($fixedCommission > 0.0) {
            $commissionPercent = 0.0;
        }

        if ($fixedCharge > 0.0) {
            $chargePercent = 0.0;
        }

        return new PriceQuote(
            faceAmount: round($amount, 2),
            rate: $rate,
            commissionPercent: $commissionPercent,
            chargePercent: $chargePercent,
            fixedCommission: $fixedCommission,
            fixedCharge: $fixedCharge,
            tieredFee: $this->tieredFee($service, $amount),
            operatorCode: $pcode !== null ? (string) $pcode : null,
            resolved: $override !== null || $base !== null,
            source: $source,
        );
    }

    /**
     * What the upline pays, used to record `parent_cost` so commission owed up
     * the chain can be reconciled without re-deriving prices later.
     */
    public function parentCost(
        Reseller $reseller,
        ServiceType $service,
        float $amount,
        string $number,
        string $numberType = '1',
        ?string $declaredOperator = null,
        ?int $packageId = null,
    ): float {
        $parent = $reseller->parent;

        if ($parent === null) {
            return 0.0;
        }

        return $this->quote($parent, $service, $amount, $number, $numberType, $declaredOperator, $packageId)
            ->payable();
    }

    // ------------------------------------------------------------ internals

    /**
     * Platform price row.
     *
     * Tries the exact service + prefix + type match the legacy code used, then
     * relaxes - first dropping the number type, then matching on operator code
     * instead of prefix. The relaxation is what lets wallet services work: they
     * store a two-character prefix like `01` rather than a real operator
     * prefix, so an exact three-digit match never succeeds for them.
     */
    private function findBasePrice(
        ServiceType $service,
        string $prefix,
        string $numberType,
        ?string $operatorCode,
    ): ?BasePrice {
        $attempts = [
            ['prefix' => $prefix, 'type' => $numberType],
            ['prefix' => $prefix],
            ['prefix' => substr($prefix, 0, 2), 'type' => $numberType],
            ['prefix' => substr($prefix, 0, 2)],
        ];

        foreach ($attempts as $where) {
            $row = BasePrice::query()
                ->where('service', $service->value)
                ->where($where)
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        if ($operatorCode !== null) {
            return BasePrice::query()
                ->where('service', $service->value)
                ->where('pcode', $operatorCode)
                ->when($numberType !== '', fn ($q) => $q->orderByRaw('type = ? DESC', [$numberType]))
                ->first();
        }

        return null;
    }

    /** Per-tariff override for this reseller, if one is configured. */
    private function findTariffRate(Reseller $reseller, ServiceType $service, string $pcode): ?TariffRate
    {
        if (blank($reseller->tarif)) {
            return null;
        }

        return TariffRate::query()
            ->where('tarif', $reseller->tarif)
            ->where('service', $service->value)
            ->where('pcode', $pcode)
            ->where('status', 1)
            ->first();
    }

    /**
     * Flat commission and charge from a package or an amount-specific offer.
     *
     * @return array{0: float, 1: float} [commission, charge]
     */
    private function packageAdjustments(
        ServiceType $service,
        float $amount,
        string $prefix,
        string $pcode,
        ?int $packageId,
    ): array {
        // An explicitly chosen bundle is authoritative.
        if ($packageId !== null) {
            $package = match ($service) {
                ServiceType::InternetPack => InternetPackage::query()->find($packageId),
                ServiceType::Flexiload    => DrivePackage::query()->find($packageId),
                default                   => null,
            };

            if ($package !== null) {
                return [(float) $package->comm, (float) $package->charge];
            }
        }

        // Otherwise look for an offer matching this exact amount on this prefix.
        $offer = FixedCommission::query()
            ->where('service', $service->value)
            ->where('prefix', $prefix)
            ->where('price', (string) (int) floor($amount))
            ->first();

        if ($offer !== null) {
            return [(float) $offer->comm, (float) $offer->charge];
        }

        // Finally, a drive package priced at the same amount for this operator.
        if ($service->usesDriveWallet()) {
            $drive = DrivePackage::query()
                ->where('status', 1)
                ->where('price', round($amount, 2))
                ->when($pcode !== '', fn ($q) => $q->where('op', $pcode))
                ->first();

            if ($drive !== null) {
                return [(float) $drive->comm, (float) $drive->charge];
            }
        }

        return [0.0, 0.0];
    }

    /**
     * Banded flat fee for postpaid bill payments.
     *
     * Preserved from the legacy ladder, which used overlapping inclusive bounds
     * (`<= 1500 && >= 401`). Expressed here as clean half-open bands so the
     * boundary amounts - 400, 1500, 5000 - land in exactly one band each.
     */
    private function tieredFee(ServiceType $service, float $amount): float
    {
        if ($service !== ServiceType::BillPay2) {
            return 0.0;
        }

        return match (true) {
            $amount <= 400   => 5.0,
            $amount <= 1500  => 10.0,
            $amount <= 5000  => 15.0,
            default          => 25.0,
        };
    }

    /**
     * First value that is actually a number, so a missing row falls through to
     * the next source instead of silently becoming zero.
     */
    private function firstNumeric(array $candidates, float $default): float
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return $default;
    }
}
