<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ResellerLevel;
use App\Models\AppToken;
use App\Models\DeviceSession;
use App\Models\LevelDefinition;
use App\Models\Reseller;
use App\Models\UserLog;
use App\Services\Security\AuditLogger;
use App\Services\Security\LegacyHasher;
use App\Services\Security\LoginThrottle;
use App\Services\Security\PinService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile app authentication.
 *
 * Response keys and values are exactly those the installed Android client
 * reads, so an existing APK keeps working. What changed is underneath: the
 * token is now a cryptographically random value rather than
 * `sha1(uniqid(rand(), true))`, which is derived from the clock and a weak PRNG
 * and is therefore guessable to within a small search space; and the login is
 * rate limited, which it previously was not at all.
 */
class AppAuthController
{
    public function __construct(
        private readonly LegacyHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly PinService $pins,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /apiapp - sign in. */
    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');
        $deviceId = trim((string) $request->input('deviceid'));
        $pushToken = trim((string) $request->input('ftoken'));
        $ip = (string) $request->ip();

        $levels = $this->levelNames();

        if ($username === '' || $password === '' || $deviceId === '') {
            return $this->json([
                'success'  => 0,
                'errors'   => 'not empty',
                'message'  => 'Please enter correct login info.',
                'stat'     => 0,
                'alllevel' => $this->levelList($levels, ResellerLevel::SubAdmin),
            ]);
        }

        if ($this->throttle->isLocked($username, $ip)) {
            return $this->json([
                'success' => 0,
                'status'  => 2,
                'stat'    => 0,
                'message' => 'Too many failed attempts. Please try again later.',
            ]);
        }

        $user = Reseller::query()
            ->where('username', $username)
            ->where('delete', '!=', 1)
            ->first();

        if ($user === null || ! $this->hasher->check($password, (string) $user->password)) {
            $this->throttle->recordFailure($username, $ip, 'app login failed', [
                'browser' => 'app',
                'os'      => 'Android',
            ]);

            return $this->json([
                'success' => 0,
                'status'  => 2,
                'stat'    => 0,
                'message' => 'Please enter correct login info.',
            ]);
        }

        // Upgrade the stored hash now that we have verified the plaintext.
        if ($this->hasher->needsRehash((string) $user->password)) {
            DB::table('reseller')->where('id', $user->id)
                ->update(['password' => $this->hasher->make($password)]);
        }

        $postLevel = $this->levelList($levels, $user->level() ?? ResellerLevel::Reseller1, below: true);

        if ((int) $user->status !== 1) {
            return $this->json([
                'success'  => 0,
                'status'   => 2,
                'stat'     => 0,
                'message'  => 'This ID is blocked.',
                'choseotp' => (int) $user->otp_choice,
            ]);
        }

        if ((int) $user->appsAccess !== 1) {
            return $this->json([
                'success'   => 1,
                'status'    => 2,
                'stat'      => 1,
                'message'   => 'Your apps access is not allowed.',
                'username'  => (string) $user->username,
                'choseotp'  => (int) $user->otp_choice,
                'postlevel' => $postLevel,
            ]);
        }

        // Device lock: a new device must be approved before it can be used.
        if ((int) $user->device_lock === 1 && ! $this->isKnownDevice($user, $deviceId)) {
            $this->audit->recordAuth($user, 'app_device_blocked', 'Blocked unknown device', [
                'device' => Str::limit($deviceId, 40, ''),
            ]);

            return $this->json([
                'success'  => 0,
                'status'   => 3,
                'stat'     => 0,
                'message'  => 'This device is not recognised. Please verify it first.',
                'choseotp' => (int) $user->otp_choice,
            ]);
        }

        $token = $this->issueToken($user, $deviceId);

        DB::table('reseller')->where('id', $user->id)->update([
            'ip'          => Str::limit($ip, 60, ''),
            'login_count' => (string) ((int) $user->login_count + 1),
            'last_login'  => now()->format('j F Y g:i A'),
        ] + ($pushToken !== '' ? ['note' => Str::limit($pushToken, 240, '')] : []));

        $this->throttle->recordSuccess($username, $ip, [
            'reseller_id' => $user->id,
            'browser'     => 'app',
            'os'          => 'Android',
        ]);

        return $this->json([
            'success'   => 1,
            'status'    => 1,
            'token'     => $token,
            'username'  => (string) $user->username,
            'otp'       => 1,
            'message'   => 'Successful Login.',
            'stat'      => 1,
            'choseotp'  => (int) $user->otp_choice,
            'postlevel' => $postLevel,
        ]);
    }

    /**
     * POST /apiapp/loginchk - is this session still valid?
     *
     * The client calls this on resume. A valid token slides its window; an
     * expired one is answered with status 2, which sends the client back to
     * the login screen.
     */
    public function login_check(Request $request): JsonResponse
    {
        return $this->check($request);
    }

    public function check(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token'));
        $deviceId = trim((string) $request->input('deviceid'));

        if ($token === '' || $deviceId === '') {
            return $this->json(['success' => 0, 'status' => 2, 'message' => 'Empty Not Work.']);
        }

        $row = AppToken::query()
            ->where('deviceid', $deviceId)
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if ($row === null || ! hash_equals((string) $row->token, $token)) {
            return $this->json(['success' => 0, 'status' => 2, 'message' => 'Not Match.']);
        }

        if ((time() - (int) $row->time) > (int) config('telecom.auth.app_idle_seconds', 1800)) {
            return $this->json(['success' => 0, 'status' => 2, 'message' => 'logout']);
        }

        DB::table('apps_key')->where('id', $row->id)->update(['time' => (string) time()]);

        return $this->json(['success' => 1, 'status' => 1, 'message' => 'logged']);
    }

    /** POST /apiapp/otp - verify the one-time code issued at login. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token'));
        $deviceId = trim((string) $request->input('deviceid'));
        $code = trim((string) $request->input('otp'));

        $row = AppToken::query()
            ->where('deviceid', $deviceId)
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if ($row === null || ! hash_equals((string) $row->token, $token)) {
            return $this->json(['success' => 0, 'status' => 2, 'message' => 'Session expired.']);
        }

        $user = Reseller::find($row->userid);

        if ($user === null) {
            return $this->json(['success' => 0, 'status' => 2, 'message' => 'Account not found.']);
        }

        if ($code === '' || ! $this->hasher->check($code, (string) $user->otp)) {
            $this->audit->recordAuth($user, 'app_otp_failed', 'Incorrect OTP from app');

            return $this->json(['success' => 0, 'status' => 2, 'message' => 'Incorrect OTP.']);
        }

        // One-time really means once: clear it so the code cannot be replayed.
        DB::table('reseller')->where('id', $user->id)->update(['otp' => '']);

        return $this->json(['success' => 1, 'status' => 1, 'message' => 'Verified.']);
    }

    /** POST /apiapp/pinchk - confirm the PIN before a sensitive screen. */
    public function checkPin(Request $request): JsonResponse
    {
        $user = $request->attributes->get('current_reseller');
        [$ok, $error] = $this->pins->verify($user, (string) $request->input('pincode'));

        return $this->json($ok
            ? ['success' => 1, 'status' => 1, 'message' => 'Verified.']
            : ['success' => 0, 'status' => 2, 'message' => (string) $error]);
    }

    /** POST /apiapp/dlock - register or confirm this device. */
    public function deviceLock(Request $request): JsonResponse
    {
        $user = $request->attributes->get('current_reseller');
        $deviceId = trim((string) $request->input('deviceid'));

        [$ok, $error] = $this->pins->verify($user, (string) $request->input('pincode'));

        if (! $ok) {
            return $this->json(['success' => 0, 'status' => 2, 'message' => (string) $error]);
        }

        DeviceSession::query()->updateOrCreate(
            ['userid' => (string) $user->id, 'tokenid' => $deviceId],
            [
                'p_id'    => (int) $user->p_id,
                'session' => Str::limit($deviceId, 95, ''),
                'ip'      => (string) $request->ip(),
                'browser' => 'Mobile App',
                'os'      => 'Android',
                'status'  => 1,
                'remember' => 1,
                'time'    => now()->format('H:i:s'),
                'date'    => now()->format('Y-m-d'),
                'country' => '',
            ],
        );

        $this->audit->recordAuth($user, 'app_device_registered', 'Registered app device', [
            'device' => Str::limit($deviceId, 40, ''),
        ]);

        return $this->json(['success' => 1, 'status' => 1, 'message' => 'Device verified.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $row = $request->attributes->get('app_token');

        if ($row instanceof AppToken) {
            DB::table('apps_key')->where('id', $row->id)->update(['status' => 0]);
        }

        return $this->json(['success' => 1, 'status' => 1, 'message' => 'Signed out.']);
    }

    // ------------------------------------------------------------ internals

    /**
     * Issue a fresh app token.
     *
     * `random_bytes` rather than the legacy `sha1(uniqid(rand(), true))`:
     * uniqid is the current time to the microsecond and rand() is seeded
     * predictably, so the old token had far less entropy than its 40 hex
     * characters suggested.
     */
    private function issueToken(Reseller $user, string $deviceId): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();

        DB::transaction(function () use ($user, $deviceId, $token, $now): void {
            // One active token per device, so signing in again on the same
            // handset replaces rather than accumulates.
            AppToken::query()
                ->where('userid', $user->id)
                ->where('deviceid', $deviceId)
                ->delete();

            AppToken::create([
                'userid'      => (int) $user->id,
                'token'       => $token,
                'deviceid'    => $deviceId,
                'date'        => now()->format('j F Y g:i A'),
                'time'        => (string) $now,
                'accesscount' => 1,
                'status'      => 1,
            ]);

            UserLog::create([
                'ucid'       => (int) $user->id,
                'ctype'      => 'user',
                'log_time'   => now()->format('H:i:s'),
                'log_date'   => now()->format('Y-m-d'),
                'secretcode' => substr(hash('sha256', $token), 0, 40),
                'tokenid'    => Str::limit($deviceId, 120, ''),
                'otpcode'    => '',
                'browser'    => 'Mobile App',
                'platform'   => 'Android',
                'logout_time' => '',
                'log_type'   => 'apps',
                'country'    => '',
                'city'       => '',
                'hostname'   => '',
                'ip'         => Str::limit((string) request()->ip(), 28, ''),
            ]);
        });

        return $token;
    }

    private function isKnownDevice(Reseller $user, string $deviceId): bool
    {
        return DeviceSession::query()
            ->where('userid', (string) $user->id)
            ->where('tokenid', $deviceId)
            ->where('status', 1)
            ->exists();
    }

    /** Display names per level, from level_list. */
    private function levelNames(): array
    {
        $custom = LevelDefinition::query()->pluck('real_name', 'name');
        $out = [];

        foreach (ResellerLevel::cases() as $level) {
            $out[$level->value] = filled($custom[$level->value] ?? null)
                ? (string) $custom[$level->value]
                : $level->defaultLabel();
        }

        return $out;
    }

    /**
     * The client expects `postlevel` as a single string of level names joined
     * by the literal sequence `","` - it splices the value straight into a JSON
     * array literal on its side. Odd, but changing it would break installed
     * apps, so the format is preserved exactly.
     */
    private function levelList(array $names, ResellerLevel $from, bool $below = false): string
    {
        $levels = $below ? $from->creatableLevels() : ResellerLevel::SubAdmin->creatableLevels();

        $labels = array_map(
            fn (ResellerLevel $l) => $names[$l->value] ?? $l->defaultLabel(),
            $levels,
        );

        return implode('","', $labels);
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload);
    }
}
