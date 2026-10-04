<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Service identifiers used by the platform.
 *
 * The legacy system stored these as a bitmask-style integer in `module.serviceid`
 * and passed them around as loose strings. The values are preserved exactly so
 * existing rows in `sendflexi`, `price`, `routing` and the mobile app keep working.
 */
enum ServiceType: int
{
    case Sms          = 2;
    case PrepaidCard  = 4;
    case BillPay      = 8;
    case BulkFlexi    = 16;
    case InternetBank = 32;
    case Flexiload    = 64;
    case Bkash        = 128;
    case Rocket       = 256;
    case GlobalFlexi  = 512;
    case MCash        = 1024;
    case UCash        = 2048;
    case MyCash       = 4096;
    case Bpo          = 8192;
    case InternetPack = 16384;
    case SureCash     = 32768;
    case MatirBank    = 65536;
    case Nagad        = 131072;
    case TeleCash     = 262144;
    case BillPay2     = 524288;

    public function label(): string
    {
        return match ($this) {
            self::Sms          => 'SMS',
            self::PrepaidCard  => 'Prepaid Card',
            self::BillPay      => 'Bill Pay',
            self::BulkFlexi    => 'Bulk Flexiload',
            self::InternetBank => 'Internet Banking',
            self::Flexiload    => 'Flexiload',
            self::Bkash        => 'bKash',
            self::Rocket       => 'Rocket',
            self::GlobalFlexi  => 'Global Flexiload',
            self::MCash        => 'mCash',
            self::UCash        => 'uCash',
            self::MyCash       => 'MyCash',
            self::Bpo          => 'BPO',
            self::InternetPack => 'Internet Pack',
            self::SureCash     => 'SureCash',
            self::MatirBank    => 'Matir Bank',
            self::Nagad        => 'Nagad',
            self::TeleCash     => 'TeleCash',
            self::BillPay2     => 'Bill Pay (Postpaid)',
        };
    }

    /** Services that recharge a mobile number directly and therefore resolve an operator by prefix. */
    public function resolvesOperatorByPrefix(): bool
    {
        return in_array($this, [self::Flexiload, self::InternetPack, self::GlobalFlexi], true);
    }

    /** Mobile-financial-service wallets (cash-in style), as opposed to airtime top-ups. */
    public function isMobileWallet(): bool
    {
        return in_array($this, [
            self::Bkash, self::Rocket, self::Nagad, self::MCash,
            self::UCash, self::MyCash, self::SureCash, self::TeleCash, self::MatirBank,
        ], true);
    }

    /** Services billed against the dedicated "drive" wallet when drive mode is enabled. */
    public function usesDriveWallet(): bool
    {
        return in_array($this, [self::Flexiload, self::InternetPack], true);
    }

    public function icon(): string
    {
        return match (true) {
            $this === self::Flexiload     => 'phone',
            $this === self::InternetPack  => 'wifi',
            $this === self::Sms           => 'chat',
            $this === self::PrepaidCard   => 'card',
            $this->isMobileWallet()       => 'wallet',
            $this === self::BillPay,
            $this === self::BillPay2      => 'receipt',
            $this === self::InternetBank  => 'bank',
            default                       => 'grid',
        };
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        return is_numeric($value) ? self::tryFrom((int) $value) : null;
    }
}
