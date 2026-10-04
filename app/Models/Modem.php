<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A physical GSM modem port used for on-premise dispatch when no upstream
 * API is configured for a route.
 */
class Modem extends Model
{
    protected $table = 'modem';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
