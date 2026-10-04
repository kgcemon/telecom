<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An outbound SMS sold through the SMS service.
 */
class SmsMessage extends Model
{
    protected $table = 'sms';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'cost'       => 'decimal:3',
            'parent_cost'=> 'decimal:3',
            'status'     => 'integer',
        ];
    }
}
