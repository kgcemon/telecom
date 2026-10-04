<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auto-captured deposit notifications matched against PaymentRequest rows.
 */
class DepositTransaction extends Model
{
    protected $table = 'trnx';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid'     => 'integer',
            'status'   => 'integer',
            'last_view'=> 'integer',
            'time'     => 'integer',
        ];
    }
}
