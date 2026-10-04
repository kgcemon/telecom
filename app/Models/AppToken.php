<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mobile-app session token bound to a device id. The Android client sends
 * this as `token` on every /apiapp request.
 */
class AppToken extends Model
{
    protected $table = 'apps_key';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'userid'     => 'integer',
            'accesscount'=> 'integer',
            'status'     => 'integer',
        ];
    }
}
