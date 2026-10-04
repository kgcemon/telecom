<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Balance movements on the operator SIMs owned by the platform, parsed from
 * operator SMS.
 */
class OperatorRecharge extends Model
{
    protected $table = 'op_recharge';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'oldbal' => 'decimal:3',
            'debit'  => 'decimal:3',
            'credit' => 'decimal:3',
            'lastbal'=> 'decimal:3',
        ];
    }
}
