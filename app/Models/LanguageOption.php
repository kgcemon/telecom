<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An enabled interface language.
 */
class LanguageOption extends Model
{
    protected $table = 'language_list';

    protected $primaryKey = 'language_list_id';

    public $timestamps = false;

    protected $guarded = ['language_list_id'];
}
