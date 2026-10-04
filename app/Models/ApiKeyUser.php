<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reseller API key for the machine-to-machine recharge API.
 */
class ApiKeyUser extends Model
{
    protected $table = 'api_key_user';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'userid'=> 'integer',
        ];
    }
}
