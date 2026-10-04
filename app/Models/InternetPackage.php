<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internet data bundles with their own commission and charge, sold under the
 * InternetPack service.
 */
class InternetPackage extends Model
{
    protected $table = 'net_package';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'comm'  => 'decimal:2',
            'charge'=> 'decimal:2',
            'status'=> 'integer',
            'exp'   => 'date',
        ];
    }
}
