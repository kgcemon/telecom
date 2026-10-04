<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IP allow-list entries bound to an API key.
 */
class ApiKeyIp extends Model
{
    protected $table = 'api_key_ip';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'userid'=> 'integer',
        ];
    }
}
