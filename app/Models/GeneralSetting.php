<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value configuration row (legacy `general_settings`).
 *
 * Read through SettingsService, which caches the whole table as one map.
 */
class GeneralSetting extends Model
{
    protected $table = 'general_settings';

    protected $primaryKey = 'general_settings_id';

    public $timestamps = false;

    protected $fillable = ['type', 'value'];
}
