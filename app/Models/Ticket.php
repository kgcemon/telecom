<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A support ticket thread.
 */
class Ticket extends Model
{
    protected $table = 'tricket_main';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'id_user'=> 'integer',
            'status' => 'integer',
        ];
    }
}
