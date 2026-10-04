<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Double-entry style ledger line for a reseller wallet (legacy `trans`).
 *
 * Exactly one of `debit` / `credit` carries a value. `oldbal` and
 * `accountbalance` snapshot the wallet either side of the movement so a
 * statement can be reconstructed without replaying every row, and so a
 * tampered balance is detectable.
 */
class LedgerEntry extends Model
{
    protected $table = 'trans';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'oldbal'         => 'decimal:2',
            'debit'          => 'decimal:2',
            'credit'         => 'decimal:2',
            'accountbalance' => 'decimal:2',
            'userid'         => 'integer',
            'service'        => 'integer',
            'status'         => 'integer',
            'send_sl'        => 'integer',
            'p_id'           => 'integer',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'userid');
    }

    public function rechargeRequest(): BelongsTo
    {
        return $this->belongsTo(RechargeRequest::class, 'send_sl');
    }

    /** Signed effect on the wallet: negative for a debit, positive for a credit. */
    public function signedAmount(): float
    {
        return (float) $this->credit - (float) $this->debit;
    }

    public function isDebit(): bool
    {
        return (float) $this->debit > 0;
    }

    public function scopeForReseller(Builder $query, int $resellerId): Builder
    {
        return $query->where('userid', $resellerId);
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('date', '>=', $from);
        }

        if ($to) {
            $query->where('date', '<=', $to);
        }

        return $query;
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('debit', '>', 0);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('credit', '>', 0);
    }
}
