<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row holding company identity, branding, contact details and the
 * deposit wallet numbers (legacy `company`, always id = 1).
 *
 * Two legacy columns are badly named and worth flagging, because reports and
 * the recharge engine both read them as flags rather than as addresses:
 *   - `zip`  acts as "force manual review for package top-ups"
 *   - `city` acts as "force manual review for internet packs"
 * They are exposed here under honest accessors; new code should use those.
 */
class Company extends Model
{
    protected $table = 'company';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'       => 'integer',
            'sec'          => 'integer',
            'nid_v'        => 'integer',
            'cert_v'       => 'integer',
            'login_theme'  => 'integer',
            'joining_fee'  => 'integer',
            'success_type' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->findOr(1, fn () => new self());
    }

    /** Hold package-based top-ups for manual review (legacy `zip` flag). */
    public function forcesManualPackageReview(): bool
    {
        return (string) $this->zip === '1';
    }

    /** Hold internet-pack requests for manual review (legacy `city` flag). */
    public function forcesManualInternetReview(): bool
    {
        return (string) $this->city === '1';
    }

    public function requiresNidVerification(): bool
    {
        return (int) $this->nid_v === 1;
    }

    public function displayName(): string
    {
        return (string) ($this->company_name ?: config('app.name'));
    }

    /** Deposit numbers keyed by channel, skipping any that are not configured. */
    public function depositNumbers(): array
    {
        return array_filter([
            'bKash'  => $this->bkash,
            'Rocket' => $this->rocket,
            'Nagad'  => $this->nogad,
            'Upay'   => $this->upay,
        ]);
    }

    public function socialLinks(): array
    {
        return array_filter([
            'whatsapp' => $this->whatsapp,
            'telegram' => $this->telegram,
            'youtube'  => $this->youtube,
            'facebook' => $this->website,
        ]);
    }
}
