<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An online payment gateway resellers use to top up their own balance.
 */
class PaymentGateway extends Model
{
    protected $table = 'payment_gateway';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_amount'=> 'integer',
            'max_amount'=> 'integer',
            'rate'      => 'decimal:2',
            'comm'      => 'decimal:2',
            'charge'    => 'decimal:2',
            'status'    => 'integer',
        ];
    }
}
