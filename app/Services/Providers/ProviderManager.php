<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\ApiProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the driver for an `api_set` row.
 *
 * Mapping lives in config/telecom.php so a deployment can add a provider by
 * registering a driver class, without editing the recharge path.
 */
class ProviderManager
{
    public function __construct(private readonly Container $container) {}

    public function for(ApiProvider $provider): ProviderGateway
    {
        $map = config('telecom.providers.drivers', []);
        $class = $map[(int) $provider->provider] ?? config('telecom.providers.default');

        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException(
                "No provider driver registered for api_set.provider = {$provider->provider}."
            );
        }

        $driver = $this->container->make($class);

        if (! $driver instanceof ProviderGateway) {
            throw new InvalidArgumentException($class.' must implement '.ProviderGateway::class);
        }

        return $driver;
    }

    /** Driver identifiers available on this deployment, for the admin UI. */
    public function available(): array
    {
        $out = [];

        foreach ((array) config('telecom.providers.drivers', []) as $id => $class) {
            if (is_string($class) && class_exists($class)) {
                $out[(int) $id] = $this->container->make($class)->name();
            }
        }

        return $out;
    }
}
