<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\ApiProvider;
use App\Models\RechargeRequest;

/**
 * Contract every upstream recharge provider implements.
 *
 * The legacy code inlined two different providers as nested if/else inside the
 * recharge routine, with their URLs, parameter names and response fields hard
 * coded. Adding a third meant editing the money path. Drivers are separate
 * classes here, chosen by `api_set.provider`, so a new provider never touches
 * the code that moves balance.
 */
interface ProviderGateway
{
    /** Stable identifier used in logs and the admin UI. */
    public function name(): string;

    /** Hand a request to the provider. */
    public function dispatch(ApiProvider $provider, RechargeRequest $request): ProviderResult;

    /** Read the provider's remaining balance, or null if unsupported. */
    public function balance(ApiProvider $provider): ?float;

    /** Re-query the status of a previously dispatched request, if supported. */
    public function status(ApiProvider $provider, RechargeRequest $request): ?ProviderResult;
}
