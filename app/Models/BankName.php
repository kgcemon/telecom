<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Banks available for the internet-banking transfer service.
 */
class BankName extends Model
{
    protected $table = 'bank_name';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }
}
