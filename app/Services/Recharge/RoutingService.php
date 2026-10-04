<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Enums\ServiceType;
use App\Models\ApiProvider;
use App\Models\Route;

/**
 * Decides who fulfils a request and whether it may be dispatched automatically.
 *
 * The legacy version looped over every matching route with a plain `foreach`
 * and kept overwriting its locals, so the route that actually got used was the
 * *last* row returned - the opposite of the `ORDER BY priority ASC` it had just
 * asked for. Operators set a priority expecting the cheapest route to win and
 * silently got the most expensive one. Here the ordering is respected and only
 * one route is taken.
 */
class RoutingService
{
    /**
     * Pick the route for a service and operator.
     *
     * `pcode = 'all'` is a catch-all, so an operator-specific route is
     * preferred over it even when the catch-all has a lower priority number.
     */
    public function resolve(ServiceType $service, ?string $operatorCode): ?Route
    {
        return Route::query()
            ->usable()
            ->where('service', $service->value)
            ->when(
                $operatorCode !== null && $operatorCode !== '',
                fn ($q) => $q->where(fn ($w) => $w->where('pcode', $operatorCode)->orWhere('pcode', 'all')),
                fn ($q) => $q->where('pcode', 'all'),
            )
            // Operator-specific beats catch-all, then lowest priority wins.
            ->orderByRaw("CASE WHEN pcode = 'all' THEN 1 ELSE 0 END")
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    /** The upstream provider behind a route, or null for manual/modem routes. */
    public function providerFor(?Route $route): ?ApiProvider
    {
        if ($route === null || $route->isManual()) {
            return null;
        }

        return ApiProvider::query()
            ->where('id', $route->route)
            ->where('status', 1)
            ->first();
    }

    /**
     * Whether this request may go out without a human approving it.
     *
     * A request is held when any of these is true:
     *  - it exceeds the service's own `auto_limit`
     *  - it exceeds the platform-wide ceiling for manual routes
     *  - the company has forced review for this class of request
     *  - no usable route exists, so there is nothing to dispatch to
     */
    public function isAutoDispatchable(
        ServiceType $service,
        float $amount,
        float $serviceAutoLimit,
        float $manualRouteCeiling,
        ?Route $route,
        bool $forcedManual = false,
    ): bool {
        if ($forcedManual || $route === null) {
            return false;
        }

        if ($amount > $serviceAutoLimit) {
            return false;
        }

        // A manual route can still auto-dispatch below the platform ceiling;
        // above it, everything waits for review.
        if ($route->isManual() && $amount > $manualRouteCeiling) {
            return false;
        }

        return true;
    }

    /**
     * Next route to try after one has failed, so a retry does not loop back to
     * the provider that just rejected the request.
     *
     * @param  array<int>  $excludeRouteIds
     */
    public function nextRoute(ServiceType $service, ?string $operatorCode, array $excludeRouteIds): ?Route
    {
        return Route::query()
            ->usable()
            ->where('service', $service->value)
            ->when(
                $operatorCode !== null && $operatorCode !== '',
                fn ($q) => $q->where(fn ($w) => $w->where('pcode', $operatorCode)->orWhere('pcode', 'all')),
                fn ($q) => $q->where('pcode', 'all'),
            )
            ->when($excludeRouteIds !== [], fn ($q) => $q->whereNotIn('id', $excludeRouteIds))
            ->orderByRaw("CASE WHEN pcode = 'all' THEN 1 ELSE 0 END")
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }
}
