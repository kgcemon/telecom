<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Inbound dispatch reports from on-premise modem software.
 */
class ReportServer extends Model
{
    protected $table = 'reportserver';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_sync'=> 'integer',
            'idate'  => 'date',
        ];
    }
}
