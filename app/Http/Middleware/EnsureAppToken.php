<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AppToken;
use App\Models\Reseller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a mobile-app request from its `token` plus `deviceid`.
 *
 * Three weaknesses in the legacy check are closed here.
 *
 * Several endpoints looked the token up by `token` alone and ignored
 * `deviceid`, so a leaked token worked from any device; others queried on both.
 * This always requires the pair to match the stored row.
 *
 * The token was compared with `==`, which in PHP compares loosely - and the
 * surrounding code then re-queried the row rather than using a constant-time
 * comparison. Comparison here is `hash_equals`.
 *
 * Expiry was checked in some endpoints and not others, so a stale token stayed
 * usable on whichever screen forgot to look. Expiry is enforced once, here.
 */
class EnsureAppToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) ($request->input('token') ?: $request->header('X-App-Token', '')));
        $deviceId = trim((string) ($request->input('deviceid') ?: $request->header('X-Device-Id', '')));

        if ($token === '' || $deviceId === '') {
            return $this->reject('Session expired. Please sign in again.');
        }

        $row = AppToken::query()
            ->where('deviceid', $deviceId)
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if ($row === null || ! hash_equals((string) $row->token, $token)) {
            return $this->reject('Session expired. Please sign in again.');
        }

        // Idle expiry, matching the legacy 30-minute window but applied
        // consistently and configurable.
        $lifetime = (int) config('telecom.auth.app_token_lifetime_days', 30) * 86400;
        $idleWindow = 1800;

        $age = time() - (int) $row->time;

        if ($age > $lifetime || $age > max($idleWindow, (int) config('telecom.auth.app_idle_seconds', 1800))) {
            return $this->reject('Session expired. Please sign in again.', status: 2);
        }

        $reseller = Reseller::find($row->userid);

        if (! $reseller instanceof Reseller || ! $reseller->isActive()) {
            return $this->reject('This account is not active.');
        }

        if ((int) $reseller->appsAccess !== 1) {
            return $this->reject('App access is disabled on this account.');
        }

        // Slide the window forward so an active session does not expire
        // mid-use, without rewriting the token itself.
        DB::table('apps_key')->where('id', $row->id)->update(['time' => (string) time()]);

        $request->attributes->set('current_reseller', $reseller);
        $request->attributes->set('app_token', $row);

        return $next($request);
    }

    /**
     * The Android client reads `success` and `status`; `status = 2` is what it
     * treats as "signed out", which makes it return to the login screen.
     */
    private function reject(string $message, int $status = 2): Response
    {
        return response()->json([
            'success' => 0,
            'status'  => $status,
            'message' => $message,
        ]);
    }
}
