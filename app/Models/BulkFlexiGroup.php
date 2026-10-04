<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named group of numbers for bulk top-up.
 */
class BulkFlexiGroup extends Model
{
    protected $table = 'bulkflexi';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty'   => 'integer',
            'status'=> 'integer',
        ];
    }
}
