<?php

declare(strict_types=1);

namespace App\Services\Wallet\Exceptions;

use App\Enums\WalletType;

/**
 * Raised when a wallet cannot cover a debit.
 *
 * Carries the figures so the caller can render an accurate message without
 * re-reading the balance (which would be outside the lock and could differ).
 */
class InsufficientBalanceException extends WalletException
{
    public function __construct(
        public readonly int $resellerId,
        public readonly WalletType $wallet,
        public readonly float $required,
        public readonly float $available,
    ) {
        parent::__construct(sprintf(
            'Insufficient %s: %s required, %s available.',
            $wallet->label(),
            number_format($required, 2),
            number_format(max(0, $available), 2),
        ));
    }

    public function shortfall(): float
    {
        return round(max(0, $this->required - $this->available), 2);
    }
}
