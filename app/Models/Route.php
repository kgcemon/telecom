<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a service plus operator code to the provider that should fulfil it.
 * The lowest `priority` wins. `route` holds either an `api_set.id` or the
 * literal string `manual`.
 */
class Route extends Model
{
    protected $table = 'routing';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'priority'=> 'integer',
            'price'   => 'decimal:2',
            'sell'    => 'decimal:2',
            'active'  => 'integer',
            'status'  => 'integer',
        ];
    }

    public const MANUAL = 'manual';

    public function isManual(): bool
    {
        return strcasecmp((string) $this->route, self::MANUAL) === 0;
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApiProvider::class, 'route');
    }

    /** active = 1 together with status = 0 is the legacy encoding for "usable". */
    public function scopeUsable($query)
    {
        return $query->where('active', 1)->where('status', 0);
    }
}
