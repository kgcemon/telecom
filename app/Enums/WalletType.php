<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three balances a reseller carries on the `reseller` row.
 *
 * Which wallet a request is billed to is decided by SettingsService:
 *  - `balance_system = 1` routes non-airtime services to the bank wallet;
 *  - `drive = 1` routes package-based airtime/internet to the drive wallet;
 *  - everything else falls back to the main wallet.
 */
enum WalletType: string
{
    case Main  = 'main';
    case Bank  = 'bank';
    case Drive = 'drive';

    /** Column on the `reseller` table that holds this balance. */
    public function column(): string
    {
        return match ($this) {
            self::Main  => 'balance',
            self::Bank  => 'bank_balance',
            self::Drive => 'drive_bal',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Main  => 'Main Balance',
            self::Bank  => 'Bank Balance',
            self::Drive => 'Drive Balance',
        };
    }

    /** Only the main wallet is constrained by `reseller.balance_limit`. */
    public function honoursCreditLimit(): bool
    {
        return $this === self::Main;
    }
}
