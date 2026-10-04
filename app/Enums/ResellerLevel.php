<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Distribution hierarchy, top to bottom. The string values are the legacy
 * `reseller.custype` values; the display names come from `level_list.real_name`
 * and are overridable per deployment.
 */
enum ResellerLevel: string
{
    case Admin     = 'admin';
    case SubAdmin  = 'subadmin';
    case Reseller5 = 'reseller5';
    case Reseller4 = 'reseller4';
    case Reseller3 = 'reseller3';
    case Reseller2 = 'reseller2';
    case Reseller1 = 'reseller1';

    /** 0 = highest authority. Used for "can this account act on that one" checks. */
    public function depth(): int
    {
        return match ($this) {
            self::Admin     => 0,
            self::SubAdmin  => 1,
            self::Reseller5 => 2,
            self::Reseller4 => 3,
            self::Reseller3 => 4,
            self::Reseller2 => 5,
            self::Reseller1 => 6,
        };
    }

    public function defaultLabel(): string
    {
        return match ($this) {
            self::Admin     => 'Administrator',
            self::SubAdmin  => 'Sub Admin',
            self::Reseller5 => 'House',
            self::Reseller4 => 'DGM',
            self::Reseller3 => 'Dealer',
            self::Reseller2 => 'Seller',
            self::Reseller1 => 'Retailer',
        };
    }

    public function isAdministrative(): bool
    {
        return in_array($this, [self::Admin, self::SubAdmin], true);
    }

    /** The level directly beneath this one, or null at the bottom of the chain. */
    public function childLevel(): ?self
    {
        return match ($this) {
            self::Admin     => self::SubAdmin,
            self::SubAdmin  => self::Reseller5,
            self::Reseller5 => self::Reseller4,
            self::Reseller4 => self::Reseller3,
            self::Reseller3 => self::Reseller2,
            self::Reseller2 => self::Reseller1,
            self::Reseller1 => null,
        };
    }

    /** Levels this account is permitted to create, i.e. everything below it. */
    public function creatableLevels(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $c) => $c->depth() > $this->depth(),
        ));
    }
}
