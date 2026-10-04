<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An internet-banking transfer request. Mirrors RechargeRequest but carries
 * bank account fields instead of a mobile number.
 */
class BankTransfer extends Model
{
    protected $table = 'bank_transfer';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance'    => 'decimal:2',
            'cost'       => 'decimal:3',
            'parent_cost'=> 'decimal:3',
            'tarif'      => 'integer',
            'status'     => 'integer',
            'refund'     => 'integer',
        ];
    }
}
