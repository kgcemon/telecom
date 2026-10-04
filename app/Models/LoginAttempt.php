<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every authentication attempt, successful or not. Drives lockout and the
 * security audit screens.
 */
class LoginAttempt extends Model
{
    protected $table = 'login_attempts';

    protected $primaryKey = 'login_attempts_id';

    public $timestamps = false;

    protected $guarded = ['login_attempts_id'];

    protected function casts(): array
    {
        return [
            'time'=> 'integer',
        ];
    }
}
