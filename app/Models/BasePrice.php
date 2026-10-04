<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The cost and commission matrix owned by the platform, keyed by service plus
 * operator prefix. `rate` is a multiplier (normally 1) while `comm` and
 * `charge` are percentages of the requested amount.
 */
class BasePrice extends Model
{
    protected $table = 'price';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'buyrate'=> 'decimal:2',
            'rate'   => 'decimal:4',
            'comm'   => 'decimal:4',
            'charge' => 'decimal:4',
        ];
    }
}
