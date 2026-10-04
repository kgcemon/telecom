<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Flat (not percentage) commission for one amount on one prefix, used for
 * offer and package pricing where a percentage would not fit.
 */
class FixedCommission extends Model
{
    protected $table = 'com_price';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'buyrate'=> 'decimal:2',
            'rate'   => 'decimal:4',
            'comm'   => 'decimal:4',
            'charge' => 'decimal:4',
        ];
    }
}
