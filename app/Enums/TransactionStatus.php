<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a `sendflexi` row.
 *
 * Integer values are the legacy ones and must not change: the Android app, the
 * provider callbacks and ~20 report queries all compare against them directly.
 */
enum TransactionStatus: int
{
    /** Queued for automatic dispatch (legacy: status 0 with local = 0). */
    case Pending = 0;

    /** Completed — the operator confirmed the top-up. */
    case Success = 1;

    /** Partially fulfilled; a smaller amount than requested was delivered. */
    case Partial = 2;

    /** Cancelled by an operator; the balance has been refunded. */
    case Cancelled = 3;

    /** Handed to a provider/modem and awaiting their confirmation. */
    case Processing = 4;

    /** Held for manual review (above auto-approval limit, or manual route). */
    case Waiting = 5;

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Success    => 'Success',
            self::Partial    => 'Partial',
            self::Cancelled  => 'Cancelled',
            self::Processing => 'Processing',
            self::Waiting    => 'Waiting',
        };
    }

    /** Tailwind badge colour family for the admin UI. */
    public function color(): string
    {
        return match ($this) {
            self::Success    => 'emerald',
            self::Cancelled  => 'rose',
            self::Waiting    => 'amber',
            self::Processing => 'sky',
            self::Partial    => 'violet',
            self::Pending    => 'slate',
        };
    }

    /** True once the money has settled and the row must not be re-dispatched. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Cancelled, self::Partial], true);
    }

    /** True when an operator may still cancel and refund the request. */
    public function isRefundable(): bool
    {
        return in_array($this, [self::Pending, self::Waiting, self::Processing], true);
    }

    /** Statuses that a dispatch worker is allowed to pick up. */
    public static function dispatchable(): array
    {
        return [self::Pending];
    }
}
