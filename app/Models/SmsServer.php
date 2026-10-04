<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credentials for an outbound SMS gateway.
 */
class SmsServer extends Model
{
    protected $table = 'sms_server';

    public $timestamps = false;

    protected $guarded = ['id'];
}
