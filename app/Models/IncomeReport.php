<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pre-aggregated daily profit snapshot.
 */
class IncomeReport extends Model
{
    protected $table = 'income_report';

    public $timestamps = false;

    protected $guarded = ['id'];
}
