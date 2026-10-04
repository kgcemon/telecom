<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credentials and endpoints for an upstream recharge provider. The
 * `provider` column selects which driver ProviderManager instantiates.
 */
class ApiProvider extends Model
{
    protected $table = 'api_set';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider'=> 'integer',
            'balance' => 'decimal:2',
            'status'  => 'integer',
        ];
    }
}
