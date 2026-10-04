<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Face values available per card operator.
 */
class CardDenomination extends Model
{
    protected $table = 'op_card_amount';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'op'    => 'integer',
            'amount'=> 'decimal:2',
        ];
    }
}
