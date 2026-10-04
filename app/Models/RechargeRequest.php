<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recharge / top-up request: the central money-moving record.
 *
 * Column notes carried over from the legacy `sendflexi` table:
 *  - `sid`          external reference shown to the customer and sent to providers
 *  - `balance`      the face amount requested (what the subscriber receives)
 *  - `cost`         what the reseller was actually debited after rate/comm/charge
 *  - `parent_cost`  what the upline was debited, for commission reconciliation
 *  - `result`       which wallet funded it (main | bank | drive)
 *  - `local`        0 = dispatch automatically, 5 = hold for manual review
 *  - `level..level5` denormalised upline ids, for commission reports
 */
class RechargeRequest extends Model
{
    protected $table = 'sendflexi';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'           => TransactionStatus::class,
            'balance'          => 'decimal:2',
            'cost'             => 'decimal:2',
            'parent_cost'      => 'decimal:3',
            'prebalance'       => 'decimal:2',
            'service'          => 'integer',
            'userid'           => 'integer',
            'p_id'             => 'integer',
            'route_id'         => 'integer',
            'tarif_id'         => 'integer',
            'api'              => 'integer',
            'local'            => 'integer',
            'refund'           => 'integer',
            'resend_count'     => 'integer',
            'sent'             => 'integer',
            'bill_status'      => 'integer',
            'submitted_date'   => 'datetime',
        ];
    }

    // ------------------------------------------------------- relationships

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'userid');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'p_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tarif_id');
    }

    public function providerResponses(): HasMany
    {
        return $this->hasMany(ProviderResponse::class, 'id_order');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'flex_id', 'sid');
    }

    // ---------------------------------------------------------- attributes

    public function serviceType(): ?ServiceType
    {
        return ServiceType::tryFromLoose($this->service);
    }

    public function serviceLabel(): string
    {
        return $this->serviceType()?->label()
            ?? (string) Service::where('serviceid', $this->service)->value('title')
            ?? 'Unknown';
    }

    public function wallet(): WalletType
    {
        return WalletType::tryFrom((string) $this->result) ?? WalletType::Main;
    }

    /** Gross margin earned by the platform on this request. */
    public function margin(): float
    {
        return (float) $this->cost - (float) $this->parent_cost;
    }

    /** True when the request is held awaiting an operator decision. */
    public function needsReview(): bool
    {
        return $this->status === TransactionStatus::Waiting;
    }

    public function isRefunded(): bool
    {
        return (int) $this->refund === 1;
    }

    /** Masked destination number for screens where the full number is not needed. */
    public function maskedPhone(): string
    {
        $phone = (string) $this->phone;

        return strlen($phone) > 6
            ? substr($phone, 0, 5).str_repeat('*', strlen($phone) - 7).substr($phone, -2)
            : $phone;
    }

    // -------------------------------------------------------------- scopes

    public function scopeStatus(Builder $query, TransactionStatus|int $status): Builder
    {
        return $query->where('status', $status instanceof TransactionStatus ? $status->value : $status);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Waiting->value);
    }

    public function scopeDispatchable(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Pending->value)->where('local', 0);
    }

    public function scopeForService(Builder $query, ServiceType|int $service): Builder
    {
        return $query->where('service', $service instanceof ServiceType ? $service->value : $service);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('idate', $date);
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

    public function scopeForReseller(Builder $query, int $resellerId): Builder
    {
        return $query->where('userid', $resellerId);
    }

    /** Requests placed by `$resellerId` or anyone beneath them in the tree. */
    public function scopeVisibleTo(Builder $query, Reseller $viewer): Builder
    {
        if ($viewer->level()?->isAdministrative()) {
            return $query;
        }

        $id = (string) $viewer->id;

        return $query->where(function (Builder $q) use ($id) {
            $q->where('userid', $id)
                ->orWhere('level', $id)
                ->orWhere('level2', $id)
                ->orWhere('level3', $id)
                ->orWhere('level4', $id)
                ->orWhere('level5', $id);
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('phone', 'like', '%'.$term.'%')
                ->orWhere('sid', 'like', '%'.$term.'%')
                ->orWhere('trxid', 'like', '%'.$term.'%');
        });
    }
}
