<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row (id = 1) holding platform-wide policy: password and PIN ageing,
 * session timeout, request throttling, daily limits, auto-approval ceilings and
 * which wallet funds which service.
 *
 * Read it through SettingsService rather than directly, so values are cached
 * and every consumer sees the same snapshot during a request.
 */
class SecurityOption extends Model
{
    protected $table = 'security_option';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'admin_capcha'         => 'integer',
            'reseller_capcha'      => 'integer',
            'pin_exp'              => 'integer',
            'pass_exp'             => 'integer',
            'pass_stong'           => 'integer',
            'min_pin'              => 'integer',
            'req_Interval'         => 'integer',
            'support'              => 'integer',
            'OTP'                  => 'integer',
            'rlverify'             => 'integer',
            'session_time_out'     => 'integer',
            'bulk_flexi_limit'     => 'integer',
            'bkash_auto'           => 'integer',
            'reseller_OverPayment' => 'integer',
            'balance_option'       => 'integer',
            'reseller_dayli_limit' => 'integer',
            'ssl_en'               => 'integer',
            'modem'                => 'integer',
            'bal_transfer'         => 'integer',
            'balance_system'       => 'integer',
            'popup'                => 'integer',
            'sms_type'             => 'integer',
            'com_system'           => 'integer',
            'drive'                => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->findOr(1, fn () => new self());
    }

    // ------------------------------------------------------------- policies

    /** Minimum seconds between two identical requests from one reseller. */
    public function requestInterval(): int
    {
        return max(0, (int) $this->req_Interval) * 60;
    }

    public function sessionTimeout(): int
    {
        return max(5, (int) $this->session_time_out);
    }

    public function requiresStrongPassword(): bool
    {
        return (int) $this->pass_stong === 1;
    }

    public function minimumPinLength(): int
    {
        return max(4, (int) $this->min_pin);
    }

    public function passwordMaxAgeDays(): ?int
    {
        return (int) $this->pass_exp > 0 ? (int) $this->pass_exp : null;
    }

    public function pinMaxAgeDays(): ?int
    {
        return (int) $this->pin_exp > 0 ? (int) $this->pin_exp : null;
    }

    public function dailyResellerLimit(): float
    {
        return (float) $this->reseller_dayli_limit;
    }

    /** Ceiling under which a manual route may still auto-dispatch. */
    public function manualRouteAutoCeiling(): float
    {
        return (float) $this->bkash_auto;
    }

    public function captchaRequiredForAdmin(): bool
    {
        return (int) $this->admin_capcha === 1;
    }

    public function captchaRequiredForReseller(): bool
    {
        return (int) $this->reseller_capcha === 1;
    }

    public function otpRequired(): bool
    {
        return (int) $this->OTP === 1;
    }

    public function balanceTransferEnabled(): bool
    {
        return (int) $this->bal_transfer === 1;
    }

    public function driveWalletEnabled(): bool
    {
        return (int) $this->drive === 1;
    }

    /** When on, non-airtime services are billed to the bank wallet. */
    public function splitWalletsEnabled(): bool
    {
        return (int) $this->balance_system === 1;
    }

    /** True when local modem dispatch is handled by external software. */
    public function modemHandledExternally(): bool
    {
        return (int) $this->modem === 1;
    }

    /** Commission mode: 0 = off, 1 = percentage, 2 = per-tariff table. */
    public function commissionMode(): int
    {
        return (int) $this->com_system;
    }

    /** Operator codes enabled for package-based (drive) top-ups. */
    public function enabledDriveOperators(): array
    {
        return array_values(array_filter([
            $this->GP, $this->RB, $this->AT, $this->TT, $this->BL, $this->SK,
        ]));
    }

    /** Which wallet funds a service, given the current policy flags. */
    public function walletFor(?\App\Enums\ServiceType $service, bool $isPackage = false): WalletType
    {
        if ($service !== null && $isPackage && $service->usesDriveWallet() && $this->driveWalletEnabled()) {
            return WalletType::Drive;
        }

        if ($this->splitWalletsEnabled() && $service !== null && ! $service->usesDriveWallet()) {
            return WalletType::Bank;
        }

        return WalletType::Main;
    }
}
