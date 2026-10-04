<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saved numbers, optionally grouped for bulk flexiload.
 */
class PhonebookEntry extends Model
{
    protected $table = 'phonebook';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gid'   => 'integer',
            'status'=> 'integer',
        ];
    }
}
