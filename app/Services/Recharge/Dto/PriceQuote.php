<?php

declare(strict_types=1);

namespace App\Services\Recharge\Dto;

/**
 * What a request will cost, with every component kept separate.
 *
 * The legacy code collapsed all of this into one `$final_amount` float and
 * recomputed it, slightly differently, in three places - the request path, the
 * balance check and the refund path - so a refund could return a different
 * figure than was taken. Quoting once into an immutable object removes that
 * class of bug: the same quote is used for the balance check, the debit and any
 * later refund.
 */
final readonly class PriceQuote
{
    public function __construct(
        /** Face value delivered to the subscriber. */
        public float $faceAmount,
        /** Multiplier applied to the face value (normally 1.0). */
        public float $rate,
        /** Percentage discount passed to the reseller. */
        public float $commissionPercent,
        /** Percentage fee added on top. */
        public float $chargePercent,
        /** Flat discount from a package or offer, in currency units. */
        public float $fixedCommission,
        /** Flat fee from a package or offer, in currency units. */
        public float $fixedCharge,
        /** Banded flat fee (bill-pay only), in currency units. */
        public float $tieredFee,
        /** Operator code the price was resolved against. */
        public ?string $operatorCode,
        /** Whether a price row was actually found. */
        public bool $resolved,
        /** Where the numbers came from, for the audit trail. */
        public string $source,
    ) {}

    /** Face value after the rate multiplier. */
    public function gross(): float
    {
        return $this->round($this->faceAmount * $this->rate);
    }

    /** Total fee charged on the request. */
    public function chargeAmount(): float
    {
        return $this->round($this->faceAmount * $this->chargePercent / 100 + $this->fixedCharge + $this->tieredFee);
    }

    /** Total discount granted to the reseller. */
    public function commissionAmount(): float
    {
        return $this->round($this->faceAmount * $this->commissionPercent / 100 + $this->fixedCommission);
    }

    /**
     * What the reseller is actually debited.
     *
     * Never returns less than zero: a commission larger than the face value
     * would otherwise credit the reseller for spending money, which the legacy
     * code allowed because it never clamped the result.
     */
    public function payable(): float
    {
        return max(0.0, $this->round($this->gross() + $this->chargeAmount() - $this->commissionAmount()));
    }

    /** Profit on the request relative to the face value. */
    public function margin(): float
    {
        return $this->round($this->payable() - $this->faceAmount);
    }

    public function toArray(): array
    {
        return [
            'face_amount'        => $this->faceAmount,
            'rate'               => $this->rate,
            'commission_percent' => $this->commissionPercent,
            'charge_percent'     => $this->chargePercent,
            'fixed_commission'   => $this->fixedCommission,
            'fixed_charge'       => $this->fixedCharge,
            'tiered_fee'         => $this->tieredFee,
            'operator_code'      => $this->operatorCode,
            'gross'              => $this->gross(),
            'charge_amount'      => $this->chargeAmount(),
            'commission_amount'  => $this->commissionAmount(),
            'payable'            => $this->payable(),
            'margin'             => $this->margin(),
            'resolved'           => $this->resolved,
            'source'             => $this->source,
        ];
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
