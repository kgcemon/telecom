<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Login and logout history with geo and platform details.
 */
class UserLog extends Model
{
    protected $table = 'userlog';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ucid'=> 'integer',
        ];
    }
}
