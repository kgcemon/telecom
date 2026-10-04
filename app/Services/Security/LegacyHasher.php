<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;

/**
 * Verifies the old password format while migrating accounts to bcrypt.
 *
 * The legacy format is `digest:iterations:algorithm:salt`, where the digest is
 * produced by chaining a plain hash 20 000 times:
 *
 *     $h = $password;
 *     for ($i = 0; $i < $iterations - 1; $i++) {
 *         $h = sha1($h . $salt . $secret);
 *     }
 *
 * That construction is weak by modern standards - unsalted-per-round SHA-1 with
 * no HMAC, and a salt drawn from `mt_rand()` - so it must not be used for new
 * credentials. It is implemented here only so existing users can sign in; on a
 * successful verification the caller rehashes the password with bcrypt and
 * writes it back, which retires the old digest after one login.
 *
 * Hash comparison uses hash_equals() to avoid leaking information through
 * response timing.
 */
class LegacyHasher
{
    /** Number of sections in a legacy hash: digest, iterations, algo, salt. */
    private const SECTIONS = 4;

    private readonly Hasher $hasher;

    public function __construct(?Hasher $hasher = null)
    {
        // Default to a self-contained bcrypt hasher so this class is usable
        // (and unit-testable) without a booted container.
        $this->hasher = $hasher ?? new BcryptHasher(['rounds' => 12]);
    }

    /** True when the stored value is in the legacy format rather than bcrypt. */
    public function isLegacy(string $stored): bool
    {
        if ($stored === '' || str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$')) {
            return false;
        }

        $parts = explode(':', $stored);

        return count($parts) === self::SECTIONS
            && ctype_digit($parts[1])
            && in_array($parts[2], hash_algos(), true);
    }

    /**
     * Check a plaintext password against either a bcrypt hash or a legacy one.
     */
    public function check(string $plain, ?string $stored): bool
    {
        if ($plain === '' || $stored === null || $stored === '') {
            return false;
        }

        if (! $this->isLegacy($stored)) {
            return $this->hasher->check($plain, $stored);
        }

        [$digest, $iterations, $algorithm, $salt] = explode(':', $stored, self::SECTIONS);

        return hash_equals($digest, $this->legacyDigest($plain, (int) $iterations, $algorithm, $salt));
    }

    /** True when a verified password should be rewritten in the modern format. */
    public function needsRehash(string $stored): bool
    {
        return $this->isLegacy($stored) || $this->hasher->needsRehash($stored);
    }

    /** Produce a modern hash for storage. */
    public function make(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    /**
     * Reproduce the legacy digest.
     *
     * The original loop was `while (--$i)` starting from $iterations, which runs
     * $iterations - 1 times. That off-by-one is part of the stored data, so it is
     * reproduced exactly rather than corrected.
     */
    private function legacyDigest(string $password, int $iterations, string $algorithm, string $salt, string $secret = ''): string
    {
        $hash = $password;

        for ($i = $iterations - 1; $i > 0; $i--) {
            $hash = hash($algorithm, $hash.$salt.$secret);
        }

        return $hash;
    }
}
