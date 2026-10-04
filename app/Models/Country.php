<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Supported countries for international top-up.
 */
class Country extends Model
{
    protected $table = 'country';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
