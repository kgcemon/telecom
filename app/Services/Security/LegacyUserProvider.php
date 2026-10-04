<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Reseller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;

/**
 * Auth provider for the `reseller` table.
 *
 * It exists so that credential checks go through LegacyHasher, which accepts
 * both the old `digest:iterations:algo:salt` format and bcrypt, and so that a
 * verified legacy password is immediately rewritten as bcrypt. Accounts
 * therefore migrate off SHA-1 one login at a time with no password reset and no
 * downtime, and the weak digest stops being the thing protecting the account
 * the moment its owner next signs in.
 */
class LegacyUserProvider implements UserProvider
{
    public function __construct(private readonly LegacyHasher $hasher) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        return Reseller::find($identifier);
    }

    /** Not used: persistent logins are tracked as DeviceSession rows instead. */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // no-op; see Reseller::setRememberToken()
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            return null;
        }

        return Reseller::query()
            ->where('username', trim($username))
            ->where('delete', '!=', 1)
            ->first();
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? null;

        if (! is_string($plain) || $plain === '') {
            return false;
        }

        return $this->hasher->check($plain, $user->getAuthPassword());
    }

    /**
     * Called by the framework after a successful login when the stored hash is
     * outdated. This is where a legacy SHA-1 digest becomes bcrypt.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        $stored = $user->getAuthPassword();
        $plain = $credentials['password'] ?? null;

        if (! is_string($plain) || $plain === '') {
            return;
        }

        if (! $force && ! $this->hasher->needsRehash($stored)) {
            return;
        }

        $fresh = $this->hasher->make($plain);

        DB::table('reseller')
            ->where('id', $user->getAuthIdentifier())
            ->update(['password' => $fresh]);

        $user->password = $fresh;
    }
}
