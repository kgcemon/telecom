<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A balance movement between two accounts (legacy `pay_receive`): an upline
 * topping up a downline, or taking balance back.
 *
 * `userid` is the account whose wallet changed and `p_id` is the account that
 * performed the action, so a single row serves both the "payments I made" and
 * "payments I received" views.
 */
class BalanceTransfer extends Model
{
    protected $table = 'pay_receive';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'debit'     => 'decimal:2',
            'credit'    => 'decimal:2',
            'account'   => 'decimal:2',
            'mbaccount' => 'decimal:0',
            'userid'    => 'integer',
            'p_id'      => 'integer',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'userid');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'p_id');
    }

    /** True when this row added balance to `userid`. */
    public function isTopUp(): bool
    {
        return (float) $this->credit > 0;
    }

    public function amount(): float
    {
        return $this->isTopUp() ? (float) $this->credit : (float) $this->debit;
    }

    public function scopeForReseller(Builder $query, int $resellerId): Builder
    {
        return $query->where('userid', $resellerId);
    }

    public function scopePerformedBy(Builder $query, int $resellerId): Builder
    {
        return $query->where('p_id', $resellerId);
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('idate', '>=', $from);
        }

        if ($to) {
            $query->where('idate', '<=', $to);
        }

        return $query;
    }
}
