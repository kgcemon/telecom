<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message inside a support ticket thread.
 */
class TicketMessage extends Model
{
    protected $table = 'tickets';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'id_user'   => 'integer',
            'tricket_id'=> 'integer',
            'ank_id'    => 'integer',
        ];
    }
}
