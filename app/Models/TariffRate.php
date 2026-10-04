<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tariff selling rate for one service and operator pair. This is what a
 * reseller is charged; BasePrice is what the platform itself pays.
 */
class TariffRate extends Model
{
    protected $table = 'rate_module';

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
