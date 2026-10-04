<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Denies one amount on one operator, used to stop loss-making or
 * operator-disabled denominations.
 */
class BlockRule extends Model
{
    protected $table = 'block_list';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'service'=> 'integer',
        ];
    }
}
