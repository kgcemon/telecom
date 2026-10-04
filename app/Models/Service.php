<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sellable service (Flexiload, bKash, Bill Pay and so on) together with its
 * validation envelope: amount range, number length, PIN requirement and the
 * `auto_limit` above which a request is held for manual review.
 */
class Service extends Model
{
    protected $table = 'module';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'serviceid'    => 'integer',
            'min_amount'   => 'decimal:2',
            'max_amount'   => 'decimal:2',
            'auto_limit'   => 'decimal:2',
            'min_length'   => 'integer',
            'max_length'   => 'integer',
            'requr_pin'    => 'integer',
            'nid'          => 'integer',
            'name'         => 'integer',
            'sender_number'=> 'integer',
            'sorder'       => 'integer',
            'status'       => 'integer',
        ];
    }

    public function type(): ?\App\Enums\ServiceType
    {
        return \App\Enums\ServiceType::tryFromLoose($this->serviceid);
    }

    public function requiresPin(): bool
    {
        return (int) $this->requr_pin === 1;
    }

    public function requiresNid(): bool
    {
        return (int) $this->nid === 1;
    }

    public function requiresCustomerName(): bool
    {
        return (int) $this->name === 1;
    }

    public function requiresSenderNumber(): bool
    {
        return (int) $this->sender_number === 1;
    }

    /** Requests at or below this amount may be dispatched without human review. */
    public function autoApprovalLimit(): float
    {
        return (float) $this->auto_limit;
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sorder')->orderBy('title');
    }
}
