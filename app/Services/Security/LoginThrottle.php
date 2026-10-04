<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\LoginAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Rate limits authentication and records every attempt.
 *
 * The legacy app wrote to `login_attempts` - the table is sitting at 90 000
 * rows - but never read it back to block anything, so there was no lockout at
 * all: an attacker could try passwords as fast as the server would answer, and
 * because `reseller.username` had no index each guess also cost a full table
 * scan. Attempts are now counted per username and per IP and both must stay
 * under the threshold.
 *
 * Counting both matters: per-IP alone lets a botnet spread guesses across
 * addresses, and per-username alone lets one IP work through a user list.
 */
class LoginThrottle
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Seconds remaining on a lockout, or 0 when the attempt may proceed. */
    public function lockedForSeconds(string $username, string $ip): int
    {
        $max = (int) config('telecom.auth.max_attempts', 5);

        foreach ([$this->userKey($username, $ip), $this->ipKey($ip)] as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return RateLimiter::availableIn($key);
            }
        }

        return 0;
    }

    public function isLocked(string $username, string $ip): bool
    {
        return $this->lockedForSeconds($username, $ip) > 0;
    }

    /** Record a failed attempt and advance both counters. */
    public function recordFailure(string $username, string $ip, string $reason, array $context = []): void
    {
        $decay = (int) config('telecom.auth.lockout_minutes', 15) * 60;

        RateLimiter::hit($this->userKey($username, $ip), $decay);
        // The per-IP window is wider so distributed guessing is still caught.
        RateLimiter::hit($this->ipKey($ip), $decay * 2);

        $this->log($username, $ip, $reason, successful: false, context: $context);
    }

    /** Clear the counters for a successful login and record it. */
    public function recordSuccess(string $username, string $ip, array $context = []): void
    {
        RateLimiter::clear($this->userKey($username, $ip));
        RateLimiter::clear($this->ipKey($ip));

        $this->log($username, $ip, 'success', successful: true, context: $context);
    }

    public function clear(string $username, string $ip): void
    {
        RateLimiter::clear($this->userKey($username, $ip));
        RateLimiter::clear($this->ipKey($ip));
    }

    /** Attempts left before lockout, for the "n attempts remaining" message. */
    public function remaining(string $username, string $ip): int
    {
        $max = (int) config('telecom.auth.max_attempts', 5);

        return max(0, RateLimiter::remaining($this->userKey($username, $ip), $max));
    }

    /**
     * Append to `login_attempts`.
     *
     * The legacy table has a `password` column and the legacy code wrote the
     * attempted password into it in clear text - so the table was a plaintext
     * credential dump, including near-misses of real passwords. Nothing is
     * written there now; the column is left in place only so the old
     * application keeps working during the transition.
     */
    private function log(string $username, string $ip, string $message, bool $successful, array $context): void
    {
        try {
            $now = Carbon::now();

            LoginAttempt::create([
                'type'       => $successful ? 'success' : 'failure',
                'ip_address' => inet_pton($ip) ?: $ip,
                'tokenid'    => Str::limit((string) ($context['token'] ?? ''), 120, ''),
                'login'      => Str::limit($username, 95, ''),
                'password'   => '',
                'session_id' => Str::limit((string) ($context['session'] ?? ''), 120, ''),
                'mesage'     => Str::limit($message, 120, ''),
                'browser'    => Str::limit((string) ($context['browser'] ?? ''), 120, ''),
                'os'         => Str::limit((string) ($context['os'] ?? ''), 120, ''),
                'date'       => $now->format('Y-m-d H:i:s'),
                'time'       => $now->getTimestamp(),
            ]);
        } catch (\Throwable) {
            // Never let audit bookkeeping block a login decision.
        }

        $this->audit->recordAuth(
            $context['reseller_id'] ?? null,
            $successful ? 'login' : 'login_failed',
            $successful ? "Signed in as {$username}" : "Failed login for {$username}: {$message}",
            ['ip' => $ip] + $context,
        );
    }

    private function userKey(string $username, string $ip): string
    {
        return 'login:'.Str::lower($username).'|'.$ip;
    }

    private function ipKey(string $ip): string
    {
        return 'login-ip:'.$ip;
    }
}
