<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mobile network or wallet operator. `pcode` holds the numeric prefix and
 * `ex` the two-letter alias used throughout pricing and routing.
 */
class Operator extends Model
{
    protected $table = 'oparetor';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'=> 'integer',
        ];
    }

    /** Numeric prefixes (017, 018 and so on) that belong to this operator. */
    public function prefixes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->pcode))));
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }
}
