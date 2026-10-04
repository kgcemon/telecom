<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\TransactionStatus;

/**
 * Outcome of one provider call.
 *
 * The distinction that matters is between `failed` and `indeterminate`. The
 * legacy code treated anything that was not an explicit success as a failure,
 * including network timeouts - so a request the provider had in fact accepted
 * could be refunded locally and then delivered anyway, losing the money. An
 * indeterminate result leaves the request in Processing for the status poller
 * to resolve, and never refunds on a guess.
 */
final readonly class ProviderResult
{
    private function __construct(
        public bool $accepted,
        public bool $indeterminate,
        public TransactionStatus $status,
        public ?string $providerTransactionId,
        public ?string $message,
        public ?string $rawResponse,
        public ?string $endpoint,
        public ?float $providerBalance,
    ) {}

    /** The provider has taken responsibility for delivering the top-up. */
    public static function accepted(
        ?string $transactionId = null,
        ?string $message = null,
        ?string $raw = null,
        ?string $endpoint = null,
        ?float $balance = null,
    ): self {
        return new self(
            accepted: true,
            indeterminate: false,
            status: TransactionStatus::Processing,
            providerTransactionId: $transactionId,
            message: $message,
            rawResponse: $raw,
            endpoint: $endpoint,
            providerBalance: $balance,
        );
    }

    /** The provider confirmed delivery outright. */
    public static function completed(
        ?string $transactionId = null,
        ?string $message = null,
        ?string $raw = null,
        ?string $endpoint = null,
        ?float $balance = null,
    ): self {
        return new self(
            accepted: true,
            indeterminate: false,
            status: TransactionStatus::Success,
            providerTransactionId: $transactionId,
            message: $message,
            rawResponse: $raw,
            endpoint: $endpoint,
            providerBalance: $balance,
        );
    }

    /** The provider explicitly rejected the request; refunding is safe. */
    public static function rejected(
        ?string $message = null,
        ?string $raw = null,
        ?string $endpoint = null,
    ): self {
        return new self(
            accepted: false,
            indeterminate: false,
            status: TransactionStatus::Pending,
            providerTransactionId: null,
            message: $message,
            rawResponse: $raw,
            endpoint: $endpoint,
            providerBalance: null,
        );
    }

    /**
     * The outcome is unknown - a timeout, an unparseable body, a 5xx. The
     * request must stay in flight and must not be refunded.
     */
    public static function unknown(
        ?string $message = null,
        ?string $raw = null,
        ?string $endpoint = null,
    ): self {
        return new self(
            accepted: false,
            indeterminate: true,
            status: TransactionStatus::Processing,
            providerTransactionId: null,
            message: $message,
            rawResponse: $raw,
            endpoint: $endpoint,
            providerBalance: null,
        );
    }

    /** True when the local balance may be returned to the reseller. */
    public function isRefundable(): bool
    {
        return ! $this->accepted && ! $this->indeterminate;
    }

    public function toArray(): array
    {
        return [
            'accepted'       => $this->accepted,
            'indeterminate'  => $this->indeterminate,
            'status'         => $this->status->value,
            'transaction_id' => $this->providerTransactionId,
            'message'        => $this->message,
            'endpoint'       => $this->endpoint,
        ];
    }
}
