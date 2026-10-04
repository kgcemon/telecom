<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Scratch-card inventory. A card moves from stock to a reseller on sale and
 * its PIN is revealed only to the buyer.
 */
class ScratchCard extends Model
{
    protected $table = 'refil_card';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:3',
            'cost'        => 'decimal:2',
            'parent_cost' => 'decimal:3',
            'last_balance'=> 'decimal:2',
            'type'        => 'integer',
            'status'      => 'integer',
        ];
    }
}
