<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator minute and offer bundles billed from the drive wallet.
 */
class DrivePackage extends Model
{
    protected $table = 'drive_package';

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
