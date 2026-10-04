<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A manual deposit channel (a bKash, Rocket, Nagad or Upay number) that
 * resellers send money to and then claim with a transaction id.
 */
class ManualGateway extends Model
{
    protected $table = 'gateway';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
            'type'  => 'integer',
        ];
    }
}
