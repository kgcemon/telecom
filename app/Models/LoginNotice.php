<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marquee text shown on the login screen.
 */
class LoginNotice extends Model
{
    protected $table = 'login_notice';

    public $timestamps = false;

    protected $guarded = ['id'];
}
