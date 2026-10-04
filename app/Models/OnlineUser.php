<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Heartbeat rows used to show who is currently online.
 */
class OnlineUser extends Model
{
    protected $table = 'user_online';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'time'=> 'integer',
        ];
    }
}
