<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator presence per country, including the dialling prefix (`oprefix`)
 * and the expected national number length used for validation.
 */
class CountryOperator extends Model
{
    protected $table = 'country_op';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
            'lenth' => 'integer',
        ];
    }
}
