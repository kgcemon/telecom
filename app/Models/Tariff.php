<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named price list. Every reseller points at one via `reseller.tarif`;
 * the rows in `rate_module` hold the per-operator numbers for that list.
 */
class Tariff extends Model
{
    protected $table = 'tarif';

    public $timestamps = false;

    protected $guarded = ['id'];
}
