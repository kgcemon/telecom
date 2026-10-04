<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResellerLevel;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Every account on the platform - administrators, sub-admins and the five
 * reseller tiers - lives in this one table, linked into a tree by `p_id`.
 *
 * The legacy schema also denormalises the chain of ancestors into level1..level5
 * so commission reports can group without recursion. Those columns are kept in
 * sync by HierarchyService; read them, never hand-edit them.
 */
class Reseller extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'reseller';

    public $timestamps = false;

    protected $hidden = [
        'password', 'pincode', 'de_pin', 'de_pass', 'gotp_key', 'otp',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance'       => 'decimal:2',
            'bank_balance'  => 'decimal:2',
            'drive_bal'     => 'decimal:2',
            'balance_limit' => 'decimal:2',
            'status'        => 'integer',
            'login_allow'   => 'integer',
            'device_safe'   => 'integer',
            'device_lock'   => 'integer',
            'enbale_otp'    => 'integer',
            'tempor_otp'    => 'integer',
            'otp_choice'    => 'integer',
            'api'           => 'integer',
            'oversale'      => 'integer',
            'webAccess'     => 'integer',
            'appsAccess'    => 'integer',
            'reseller'      => 'integer',
            'recharge'      => 'integer',
            'delete'        => 'integer',
            'fb_p'          => 'integer',
            'p_id'          => 'integer',
            'lft'           => 'integer',
            'rgt'           => 'integer',
        ];
    }

    // ---------------------------------------------------------------- auth

    public function getAuthPassword(): string
    {
        return (string) $this->password;
    }

    /**
     * The legacy table has no remember_token column, so "remember me" is backed
     * by DeviceSession instead - which also records IP, browser and OS so an
     * admin can audit and revoke a persistent login.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op by design; see getRememberToken()
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    // ------------------------------------------------------- relationships

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'p_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'p_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tarif');
    }

    public function rechargeRequests(): HasMany
    {
        return $this->hasMany(RechargeRequest::class, 'userid');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'userid');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceSession::class, 'userid');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(MenuPermission::class, 'userid');
    }

    // ---------------------------------------------------------- attributes

    public function level(): ?ResellerLevel
    {
        return ResellerLevel::tryFrom((string) $this->custype);
    }

    public function isAdministrative(): bool
    {
        return (bool) $this->level()?->isAdministrative();
    }

    public function isActive(): bool
    {
        return (int) $this->status === 1 && (int) $this->delete !== 1;
    }

    public function balanceFor(WalletType $wallet): string
    {
        return (string) ($this->{$wallet->column()} ?? '0.00');
    }

    /**
     * Balance actually usable for a purchase. The main wallet reserves
     * `balance_limit` as a floor the reseller may not spend through.
     */
    public function spendableBalance(WalletType $wallet): float
    {
        $balance = (float) $this->balanceFor($wallet);

        return $wallet->honoursCreditLimit()
            ? $balance - (float) $this->balance_limit
            : $balance;
    }

    /**
     * Ancestor ids, nearest first.
     *
     * The legacy table stores this chain *backwards*: `level5` holds the
     * immediate parent and `level1` the most distant ancestor. Note that
     * `sendflexi` uses the opposite convention for its own level columns
     * (`level` is nearest there), so the two must not be copied across
     * positionally. Reading always goes through this method so that trap is in
     * exactly one place.
     */
    public function ancestorIds(): array
    {
        $ids = [$this->p_id, $this->level5, $this->level4, $this->level3, $this->level2, $this->level1];

        $ids = array_values(array_filter($ids, fn ($id) => ! empty($id) && (int) $id > 0));

        // The legacy writer padded unused slots with 0 and sometimes repeated
        // the parent, so de-duplicate while preserving order.
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function displayName(): string
    {
        return trim((string) $this->name) !== '' ? (string) $this->name : (string) $this->username;
    }

    // -------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1)->where('delete', '!=', 1);
    }

    public function scopeLevel(Builder $query, ResellerLevel|string $level): Builder
    {
        return $query->where('custype', $level instanceof ResellerLevel ? $level->value : $level);
    }

    public function scopeAdministrative(Builder $query): Builder
    {
        return $query->whereIn('custype', [ResellerLevel::Admin->value, ResellerLevel::SubAdmin->value]);
    }

    /** Restrict a listing to the accounts below `$id` in the tree. */
    public function scopeDownlineOf(Builder $query, int $id): Builder
    {
        return $query->where(function (Builder $q) use ($id) {
            $q->where('p_id', $id)
                ->orWhere('level1', (string) $id)
                ->orWhere('level2', (string) $id)
                ->orWhere('level3', (string) $id)
                ->orWhere('level4', (string) $id)
                ->orWhere('level5', (string) $id);
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('username', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('mobile', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%');
        });
    }
}
