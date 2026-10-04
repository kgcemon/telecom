<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Company;
use App\Models\GeneralSetting;
use App\Models\SecurityOption;
use Illuminate\Support\Facades\Cache;

/**
 * Single read path for platform configuration.
 *
 * The legacy code re-queried `security_option` and `company` dozens of times
 * per request (often inside loops), which made behaviour depend on query order
 * and made settings impossible to reason about. Everything funnels through here
 * instead: values are resolved once, cached, and invalidated on write.
 */
class SettingsService
{
    private const CACHE_TTL = 300;

    private const KEY_SECURITY = 'settings.security';
    private const KEY_COMPANY  = 'settings.company';
    private const KEY_GENERAL  = 'settings.general';

    private ?SecurityOption $security = null;

    private ?Company $company = null;

    /** @var array<string,string|null>|null */
    private ?array $general = null;

    public function security(): SecurityOption
    {
        return $this->security ??= Cache::remember(
            self::KEY_SECURITY,
            self::CACHE_TTL,
            fn () => SecurityOption::current(),
        );
    }

    public function company(): Company
    {
        return $this->company ??= Cache::remember(
            self::KEY_COMPANY,
            self::CACHE_TTL,
            fn () => Company::current(),
        );
    }

    /** A key/value setting from the `general_settings` table. */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->general ??= Cache::remember(
            self::KEY_GENERAL,
            self::CACHE_TTL,
            fn () => GeneralSetting::query()->pluck('value', 'type')->all(),
        );

        $value = $this->general[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function put(string $key, mixed $value): void
    {
        GeneralSetting::query()->updateOrCreate(
            ['type' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value)],
        );

        $this->forget();
    }

    /** Persist a change to the security policy row and drop the cache. */
    public function updateSecurity(array $attributes): SecurityOption
    {
        $row = SecurityOption::current();
        $row->forceFill($attributes)->save();

        $this->forget();

        return $row;
    }

    public function updateCompany(array $attributes): Company
    {
        $row = Company::current();
        $row->forceFill($attributes)->save();

        $this->forget();

        return $row;
    }

    public function forget(): void
    {
        Cache::forget(self::KEY_SECURITY);
        Cache::forget(self::KEY_COMPANY);
        Cache::forget(self::KEY_GENERAL);

        $this->security = null;
        $this->company = null;
        $this->general = null;
    }

    // ------------------------------------------------------- convenience

    public function currencySymbol(): string
    {
        return (string) $this->get('currency_symbol', 'Tk');
    }

    public function timezone(): string
    {
        return (string) $this->get('timezone', config('app.timezone', 'Asia/Dhaka'));
    }

    public function supportEnabled(): bool
    {
        return (int) $this->security()->support === 1;
    }
}
