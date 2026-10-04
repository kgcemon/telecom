<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A utility bill payment request.
 */
class BillPayment extends Model
{
    protected $table = 'bill_pay';

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
