<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Utility billers available for the bill-pay service.
 */
class BillProvider extends Model
{
    protected $table = 'billing_set';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
