<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Display name and per-level deposit numbers for each reseller tier.
 */
class LevelDefinition extends Model
{
    protected $table = 'level_list';

    public $timestamps = false;

    protected $guarded = ['id'];
}
