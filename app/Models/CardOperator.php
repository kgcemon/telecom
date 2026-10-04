<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operators whose scratch cards the platform sells.
 */
class CardOperator extends Model
{
    protected $table = 'op_card';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
