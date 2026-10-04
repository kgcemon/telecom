<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A remembered device, retained so an admin can audit and revoke access per
 * device rather than only per account.
 */
class DeviceSession extends Model
{
    protected $table = 'device_list';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'p_id'    => 'integer',
            'remember'=> 'integer',
            'status'  => 'integer',
        ];
    }
}
