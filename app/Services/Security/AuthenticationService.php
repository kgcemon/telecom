<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\DeviceSession;
use App\Models\Reseller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single sign-in path for both panels.
 *
 * Centralising it fixes an authorisation gap in the legacy app: each controller
 * decided for itself whether the session belonged to an admin, and several
 * never checked, so a reseller session could reach admin pages by URL. Here the
 * guard is chosen from the account's own `custype` and the panel being entered,
 * and a mismatch is a refusal.
 */
class AuthenticationService
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Attempt a sign-in.
     *
     * @param  string  $panel  'admin' or 'reseller'
     * @return array{0: bool, 1: string|null, 2: Reseller|null} [ok, error, user]
     */
    public function attempt(Request $request, string $username, string $password, string $panel): array
    {
        $ip = (string) $request->ip();
        $username = trim($username);

        if ($seconds = $this->throttle->lockedForSeconds($username, $ip)) {
            return [
                false,
                sprintf('Too many failed attempts. Please try again in %d minute(s).', (int) ceil($seconds / 60)),
                null,
            ];
        }

        $context = [
            'browser' => Str::limit((string) $request->userAgent(), 110, ''),
            'os'      => $this->platform($request),
            'session' => $request->hasSession() ? $request->session()->getId() : '',
        ];

        $guard = $panel === 'admin' ? 'admin' : 'reseller';

        // Resolve the account first so panel and status can be checked before
        // the credentials are accepted into a session.
        $candidate = Reseller::query()
            ->where('username', $username)
            ->where('delete', '!=', 1)
            ->first();

        if ($candidate === null) {
            $this->throttle->recordFailure($username, $ip, 'unknown account', $context);

            // Deliberately the same message as a wrong password, so the form
            // cannot be used to enumerate which usernames exist.
            return [false, 'The username or password is incorrect.', null];
        }

        if (! Auth::guard($guard)->validate(['username' => $username, 'password' => $password])) {
            $this->throttle->recordFailure(
                $username, $ip, 'wrong password',
                $context + ['reseller_id' => $candidate->id],
            );

            $remaining = $this->throttle->remaining($username, $ip);

            return [
                false,
                $remaining > 0 && $remaining <= 2
                    ? sprintf('The username or password is incorrect. %d attempt(s) remaining.', $remaining)
                    : 'The username or password is incorrect.',
                null,
            ];
        }

        // Credentials are right; now the account-level gates.
        if ($error = $this->accountGate($candidate, $panel, $request)) {
            $this->throttle->recordFailure(
                $username, $ip, $error,
                $context + ['reseller_id' => $candidate->id],
            );

            return [false, $error, null];
        }

        Auth::guard($guard)->login($candidate);

        // A fresh session id after privilege change defeats session fixation,
        // which the legacy app was open to because it reused the pre-login id.
        $request->session()->regenerate();

        $this->afterLogin($candidate, $request);

        $this->throttle->recordSuccess($username, $ip, $context + ['reseller_id' => $candidate->id]);

        return [true, null, $candidate];
    }

    /**
     * Account-level conditions, checked only after the password is verified so
     * the messages cannot be used to probe for valid accounts.
     */
    private function accountGate(Reseller $user, string $panel, Request $request): ?string
    {
        if (! $user->isActive()) {
            return 'This account is suspended. Please contact support.';
        }

        if ((int) $user->login_allow !== 1) {
            return 'Sign-in is disabled on this account.';
        }

        $level = $user->level();

        if ($level === null) {
            return 'This account has no role assigned. Please contact support.';
        }

        // An admin may enter the reseller panel; a reseller may never enter the
        // admin panel.
        if ($panel === 'admin' && ! $level->isAdministrative()) {
            return 'This account is not permitted to use the admin panel.';
        }

        if ((int) $user->webAccess !== 1) {
            return 'Web access is disabled on this account. Please use the mobile app.';
        }

        if ((int) $user->device_lock === 1 && ! $this->isKnownDevice($user, $request)) {
            return 'This device is not recognised. Please verify it before signing in.';
        }

        return null;
    }

    /** True when this device has signed in successfully before. */
    private function isKnownDevice(Reseller $user, Request $request): bool
    {
        return DeviceSession::query()
            ->where('userid', (string) $user->id)
            ->where('status', 1)
            ->where('browser', Str::limit((string) $request->userAgent(), 250, ''))
            ->where('ip', (string) $request->ip())
            ->exists();
    }

    /** Housekeeping after a successful sign-in. */
    private function afterLogin(Reseller $user, Request $request): void
    {
        $now = Carbon::now();

        DB::table('reseller')->where('id', $user->id)->update([
            'last_login'  => $now->format('Y-m-d H:i:s'),
            'login_count' => (string) ((int) $user->login_count + 1),
            'ip'          => Str::limit((string) $request->ip(), 60, ''),
        ]);

        DeviceSession::query()->updateOrCreate(
            [
                'userid'  => (string) $user->id,
                'session' => $request->hasSession() ? Str::limit($request->session()->getId(), 95, '') : '',
            ],
            [
                'p_id'     => (int) $user->p_id,
                'ip'       => Str::limit((string) $request->ip(), 250, ''),
                'browser'  => Str::limit((string) $request->userAgent(), 250, ''),
                'os'       => $this->platform($request),
                'status'   => 1,
                'remember' => 0,
                'time'     => $now->format('H:i:s'),
                'date'     => $now->format('Y-m-d'),
                'country'  => '',
            ],
        );
    }

    /** End a session and retire its device row. */
    public function logout(Request $request, string $panel): void
    {
        $guard = $panel === 'admin' ? 'admin' : 'reseller';
        $user = Auth::guard($guard)->user();

        if ($user instanceof Reseller && $request->hasSession()) {
            DeviceSession::query()
                ->where('userid', (string) $user->id)
                ->where('session', Str::limit($request->session()->getId(), 95, ''))
                ->update(['status' => 0]);

            $this->audit->recordAuth($user, 'logout', 'Signed out');
        }

        Auth::guard($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function platform(Request $request): string
    {
        $agent = (string) $request->userAgent();

        return match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS')  => 'macOS',
            str_contains($agent, 'Linux')   => 'Linux',
            default                         => 'Unknown',
        };
    }
}
