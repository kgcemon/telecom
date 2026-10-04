<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransactionStatus;
use App\Models\RechargeRequest;
use App\Models\Route;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ProviderResult;
use App\Services\Recharge\RechargeService;
use App\Services\Recharge\RoutingService;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands a committed request to its provider, outside the request/response cycle.
 *
 * Running this as a job is what keeps the provider HTTP call off the critical
 * path: the legacy code called cURL inline with no timeout while a database
 * transaction was open, so one slow provider stalled workers and connections
 * until the pool was exhausted.
 *
 * The job is unique per request id for the duration of its attempt, so a
 * double-queued request cannot be sent to the provider twice. It also claims
 * the row with a conditional update before dispatching, which closes the same
 * window at the database level in case two workers pick it up.
 */
class DispatchRechargeRequest implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** Back off between attempts rather than hammering a struggling provider. */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $requestId) {}

    public function uniqueId(): string
    {
        return 'recharge-dispatch:'.$this->requestId;
    }

    public function handle(
        RechargeService $recharge,
        RoutingService $routing,
        ProviderManager $providers,
        SettingsService $settings,
    ): void {
        // Claim the row: only a request still waiting to go out may be sent,
        // and claiming flips it to Processing so nothing else picks it up.
        $claimed = RechargeRequest::query()
            ->where('id', $this->requestId)
            ->where('status', TransactionStatus::Pending->value)
            ->where('refund', '!=', 1)
            ->update([
                'status'      => TransactionStatus::Processing->value,
                'update_time' => now()->format('Y-m-d H:i:s'),
            ]);

        if ($claimed !== 1) {
            // Already dispatched, cancelled or held for review.
            return;
        }

        $request = RechargeRequest::find($this->requestId);

        if ($request === null) {
            return;
        }

        $route = $request->route_id
            ? Route::find($request->route_id)
            : $routing->resolve($request->serviceType(), $request->pcode);

        // A manual route, or no route at all, means a human or the on-premise
        // modem software fulfils this; park it in the review queue.
        if ($route === null || $route->isManual()) {
            $request->forceFill([
                'status' => TransactionStatus::Waiting->value,
                'local'  => 5,
            ])->save();

            return;
        }

        $provider = $routing->providerFor($route);

        if ($provider === null) {
            Log::warning('Recharge route has no usable provider', [
                'request_id' => $request->id,
                'route_id'   => $route->id,
            ]);

            $request->forceFill([
                'status' => TransactionStatus::Waiting->value,
                'local'  => 5,
            ])->save();

            return;
        }

        $result = $providers->for($provider)->dispatch($provider, $request);

        $recharge->applyProviderResult($request, $result);

        // Record the provider balance when it told us, so the panel can warn
        // before a provider runs dry.
        if ($result->providerBalance !== null) {
            DB::table('api_set')
                ->where('id', $provider->id)
                ->update(['balance' => $result->providerBalance]);
        }
    }

    /**
     * A job that exhausts its retries must not leave the request stuck in
     * Processing forever; park it for review instead, without refunding, since
     * we cannot prove the provider did not accept it.
     */
    public function failed(?\Throwable $e): void
    {
        RechargeRequest::query()
            ->where('id', $this->requestId)
            ->where('status', TransactionStatus::Processing->value)
            ->update([
                'status'      => TransactionStatus::Waiting->value,
                'local'       => 5,
                'remark'      => DB::raw("CONCAT(COALESCE(remark,''), ' | dispatch failed, needs review')"),
                'update_time' => now()->format('Y-m-d H:i:s'),
            ]);

        Log::error('Recharge dispatch permanently failed', [
            'request_id' => $this->requestId,
            'error'      => $e?->getMessage(),
        ]);
    }
}
