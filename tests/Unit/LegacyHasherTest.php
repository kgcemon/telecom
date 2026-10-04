<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Security\LegacyHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks in compatibility with the old password format.
 *
 * If these fail, existing users cannot log in - so they exist to catch a
 * well-meaning "cleanup" of the hashing code.
 */
class LegacyHasherTest extends TestCase
{
    private LegacyHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new LegacyHasher();
    }

    /**
     * Verbatim copy of the legacy Mdb::generate(), so the test compares
     * against the original behaviour rather than against our own assumptions.
     */
    private function legacyGenerate(
        string $password,
        string $salt,
        int $iterations = 20000,
        string $hashFunction = 'sha1',
        string $secret = '',
    ): string {
        $hash = $password;
        $i = $iterations;

        while (--$i) {
            $hash = $hashFunction($hash.$salt.$secret);
        }

        return implode(':', [$hash, $iterations, $hashFunction, $salt]);
    }

    public static function legacyPasswords(): array
    {
        return [
            'simple'          => ['secret123', 'a1b2c3d4e5f6a7b8c9d0'],
            'symbols'         => ['P@ssw0rd!', 'ffffffffffffffffffff'],
            'numeric'         => ['1234', '0011223344556677'],
            'with space'      => ['bangla pass', 'abcdefabcdefabcdef'],
            'long'            => [str_repeat('x', 72), 'abcdef0123456789'],
            'unicode'         => ['পাসওয়ার্ড', 'abcdef0123456789'],
        ];
    }

    #[DataProvider('legacyPasswords')]
    public function test_it_verifies_legacy_hashes(string $password, string $salt): void
    {
        $stored = $this->legacyGenerate($password, $salt);

        $this->assertTrue($this->hasher->isLegacy($stored));
        $this->assertTrue($this->hasher->check($password, $stored));
    }

    #[DataProvider('legacyPasswords')]
    public function test_it_rejects_wrong_passwords(string $password, string $salt): void
    {
        $stored = $this->legacyGenerate($password, $salt);

        $this->assertFalse($this->hasher->check($password.'x', $stored));
        $this->assertFalse($this->hasher->check(strtoupper($password).'!', $stored));
    }

    public function test_it_handles_non_default_iterations_and_algorithms(): void
    {
        $stored = $this->legacyGenerate('alt', 'abcdefabcdefabcdef', 5000, 'md5');

        $this->assertTrue($this->hasher->check('alt', $stored));
        $this->assertFalse($this->hasher->check('nope', $stored));
    }

    public function test_legacy_hashes_are_flagged_for_upgrade(): void
    {
        $stored = $this->legacyGenerate('secret123', 'a1b2c3d4e5f6a7b8c9d0');

        $this->assertTrue($this->hasher->needsRehash($stored));
    }

    public function test_modern_hashes_are_not_treated_as_legacy(): void
    {
        $modern = $this->hasher->make('secret123');

        $this->assertFalse($this->hasher->isLegacy($modern));
        $this->assertFalse($this->hasher->needsRehash($modern));
        $this->assertTrue($this->hasher->check('secret123', $modern));
        $this->assertFalse($this->hasher->check('wrong', $modern));
    }

    public function test_weaker_cost_bcrypt_still_verifies_but_is_upgraded(): void
    {
        $weak = password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertTrue($this->hasher->check('secret123', $weak));
        $this->assertTrue($this->hasher->needsRehash($weak));
    }

    public function test_empty_and_null_inputs_are_rejected(): void
    {
        $this->assertFalse($this->hasher->check('', 'anything'));
        $this->assertFalse($this->hasher->check('password', null));
        $this->assertFalse($this->hasher->check('password', ''));
    }

    public function test_malformed_stored_values_are_not_treated_as_legacy(): void
    {
        foreach (['', 'plaintext', 'a:b', 'a:b:c', 'hash:notanumber:sha1:salt', 'hash:1000:nosuchalgo:salt'] as $value) {
            $this->assertFalse($this->hasher->isLegacy($value), "treated as legacy: {$value}");
        }
    }
}
