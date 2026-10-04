<?php

declare(strict_types=1);

namespace App\Services\Recharge\Dto;

use App\Enums\TransactionStatus;
use App\Models\RechargeRequest;

/**
 * Result of placing a request.
 *
 * The legacy routine returned an array shaped `['irsts' => 'error'|'success',
 * 'irsmessage' => '...']` and, on several failure branches, simply fell through
 * without assigning it at all - so the caller received null and reported
 * success. Every path here returns a populated outcome, and the failure reason
 * is a stable machine-readable code as well as a message.
 */
final readonly class RechargeOutcome
{
    private function __construct(
        public bool $successful,
        public ?RechargeRequest $request,
        public ?TransactionStatus $status,
        public string $message,
        public ?string $reasonCode,
        public ?PriceQuote $quote,
    ) {}

    public static function accepted(
        RechargeRequest $request,
        PriceQuote $quote,
        string $message,
    ): self {
        return new self(
            successful: true,
            request: $request,
            status: $request->status,
            message: $message,
            reasonCode: null,
            quote: $quote,
        );
    }

    public static function failed(string $reasonCode, string $message, ?PriceQuote $quote = null): self
    {
        return new self(
            successful: false,
            request: null,
            status: null,
            message: $message,
            reasonCode: $reasonCode,
            quote: $quote,
        );
    }

    /** Shape expected by the existing Android client. */
    public function toLegacyArray(): array
    {
        return [
            'irsts'      => $this->successful ? 'success' : 'error',
            'irsmessage' => $this->message,
        ];
    }

    public function toArray(): array
    {
        return [
            'success'     => $this->successful,
            'message'     => $this->message,
            'reason_code' => $this->reasonCode,
            'status'      => $this->status?->value,
            'status_text' => $this->status?->label(),
            'reference'   => $this->request?->sid,
            'request_id'  => $this->request?->id,
            'charged'     => $this->quote?->payable(),
        ];
    }
}
