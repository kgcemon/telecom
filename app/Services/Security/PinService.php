<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Reseller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Transaction PIN verification.
 *
 * Three problems with the legacy check are closed here.
 *
 * The old `otpchk()` accepted the literal strings `api`, `ets` and `offline` as
 * valid PINs: any caller that sent `pincode=api` skipped PIN verification
 * entirely, and that value was reachable from the public request path. Those
 * bypasses are gone - a channel that genuinely does not use PINs is declared
 * by the caller through `$pinRequired`, which is derived from the service
 * configuration rather than from user input.
 *
 * There was also no attempt limiting, so a 4-digit PIN could be brute-forced in
 * a few thousand requests. Attempts are now counted per account and the PIN is
 * locked out after a threshold.
 *
 * Finally, comparison is constant-time, so response timing cannot be used to
 * learn a PIN digit by digit.
 */
class PinService
{
    public function __construct(private readonly LegacyHasher $hasher) {}

    /**
     * Verify a PIN, counting failures.
     *
     * @return array{0: bool, 1: string|null} [ok, failureMessage]
     */
    public function verify(Reseller $reseller, ?string $pin): array
    {
        $key = $this->attemptKey($reseller);
        $max = (int) config('telecom.auth.max_pin_attempts', 5);

        if ($max > 0 && (int) Cache::get($key, 0) >= $max) {
            return [false, 'Too many incorrect PIN attempts. Please try again later or contact support.'];
        }

        if ($pin === null || trim($pin) === '') {
            return [false, 'Please enter your transaction PIN.'];
        }

        $stored = (string) $reseller->pincode;

        if ($stored === '') {
            return [false, 'No transaction PIN is set on your account. Please set one first.'];
        }

        if (! $this->hasher->check(trim($pin), $stored)) {
            $attempts = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $attempts, now()->addMinutes((int) config('telecom.auth.lockout_minutes', 15)));

            $remaining = max(0, $max - $attempts);

            return [
                false,
                $remaining > 0
                    ? sprintf('Incorrect PIN. %d attempt(s) remaining.', $remaining)
                    : 'Incorrect PIN. Your PIN is now locked. Please contact support.',
            ];
        }

        Cache::forget($key);

        // Retire the weak legacy digest on first successful use.
        if ($this->hasher->needsRehash($stored)) {
            $this->store($reseller, trim($pin));
        }

        return [true, null];
    }

    /** Hash and persist a new PIN. */
    public function store(Reseller $reseller, string $pin): void
    {
        DB::table('reseller')
            ->where('id', $reseller->id)
            ->update([
                'pincode'       => $this->hasher->make($pin),
                'chnage_pin_d'  => now()->format('Y-m-d'),
                'chnage_pin_t'  => now()->format('H:i:s'),
            ]);

        $reseller->pincode = $this->hasher->make($pin);

        Cache::forget($this->attemptKey($reseller));
    }

    /** Clear a lockout, for an admin unlocking an account. */
    public function clearAttempts(Reseller $reseller): void
    {
        Cache::forget($this->attemptKey($reseller));
    }

    public function isLockedOut(Reseller $reseller): bool
    {
        $max = (int) config('telecom.auth.max_pin_attempts', 5);

        return $max > 0 && (int) Cache::get($this->attemptKey($reseller), 0) >= $max;
    }

    /**
     * Whether a PIN meets policy: long enough, and not a trivially guessable
     * sequence. The legacy code enforced only a minimum length, so `1111` and
     * `1234` were both accepted.
     */
    public function validateStrength(string $pin, int $minLength): ?string
    {
        $pin = trim($pin);

        if (! ctype_digit($pin)) {
            return 'PIN must contain digits only.';
        }

        if (strlen($pin) < $minLength) {
            return sprintf('PIN must be at least %d digits.', $minLength);
        }

        if (preg_match('/^(\d)\1+$/', $pin)) {
            return 'PIN cannot be the same digit repeated.';
        }

        if ($this->isSequential($pin)) {
            return 'PIN cannot be a simple sequence such as 1234.';
        }

        return null;
    }

    private function isSequential(string $pin): bool
    {
        $ascending = true;
        $descending = true;

        for ($i = 1, $len = strlen($pin); $i < $len; $i++) {
            $delta = (int) $pin[$i] - (int) $pin[$i - 1];

            if ($delta !== 1) {
                $ascending = false;
            }

            if ($delta !== -1) {
                $descending = false;
            }
        }

        return $ascending || $descending;
    }

    private function attemptKey(Reseller $reseller): string
    {
        return 'pin-attempts:'.$reseller->id;
    }
}
