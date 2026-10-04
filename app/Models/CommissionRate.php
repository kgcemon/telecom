<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tariff commission overrides, used when the deployment runs in
 * commission mode rather than discount mode (`security_option.com_system`).
 */
class CommissionRate extends Model
{
    protected $table = 'rate_com';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate'  => 'decimal:4',
            'comm'  => 'decimal:4',
            'charge'=> 'decimal:4',
            'status'=> 'integer',
        ];
    }
}
