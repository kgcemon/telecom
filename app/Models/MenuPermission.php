<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-account feature flags for the panel. Every column is one permission;
 * PermissionService holds the canonical list and the human labels.
 */
class MenuPermission extends Model
{
    protected $table = 'menu_list';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'userid'=> 'integer',
            'status'=> 'integer',
        ];
    }
}
