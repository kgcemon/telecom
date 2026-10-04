<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One translatable string across the supported languages.
 */
class Translation extends Model
{
    protected $table = 'language';

    protected $primaryKey = 'word_id';

    public $timestamps = false;

    protected $guarded = ['word_id'];
}
