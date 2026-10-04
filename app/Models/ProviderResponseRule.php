<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Parsing rules that turn a provider reply into a definite success or
 * failure by locating the number, amount and transaction id between known
 * delimiters.
 */
class ProviderResponseRule extends Model
{
    protected $table = 'response_from_provider';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prioriti'  => 'integer',
            'trx_status'=> 'integer',
            'status'    => 'integer',
        ];
    }
}
