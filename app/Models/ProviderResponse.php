<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw provider replies, kept for audit and dispute handling.
 */
class ProviderResponse extends Model
{
    protected $table = 'response';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'id_order'=> 'integer',
        ];
    }
}
