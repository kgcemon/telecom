<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Display currency and its exchange rate.
 */
class Currency extends Model
{
    protected $table = 'currency_settings';

    protected $primaryKey = 'currency_settings_id';

    public $timestamps = false;

    protected $guarded = ['currency_settings_id'];
}
