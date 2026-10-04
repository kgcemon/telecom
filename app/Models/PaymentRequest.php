<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A claim that money has been deposited, awaiting verification.
 */
class PaymentRequest extends Model
{
    protected $table = 'payment_rquest';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
