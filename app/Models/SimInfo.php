<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A SIM seated in a modem, with the USSD string used to read its balance.
 */
class SimInfo extends Model
{
    protected $table = 'siminfo';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'auto'     => 'integer',
            'triger'   => 'integer',
            'offer'    => 'integer',
            'powerload'=> 'integer',
        ];
    }
}
