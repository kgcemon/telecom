<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * White-label branding for a sub-admin domain: title, theme, notice and
 * footer text.
 */
class Brand extends Model
{
    protected $table = 'sub_admin_brand';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'theme'=> 'integer',
        ];
    }
}
