<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What an unauthenticated visitor may reach.
 *
 * The legacy panel relied on each controller to check the session, and several
 * did not, so pages were reachable by URL without signing in. These assertions
 * exist so that a missing guard shows up as a failing test rather than as an
 * exposed page.
 */
class GuestAccessTest extends TestCase
{
    public function test_root_sends_a_guest_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect(route('reseller.login'));
    }

    public function test_sign_in_pages_are_reachable(): void
    {
        $this->get(route('reseller.login'))->assertOk();
        $this->get(route('admin.login'))->assertOk();
    }

    /**
     * Every panel page must refuse a guest. A 200 here would mean a screen is
     * served without authentication.
     */
    public function test_panel_pages_reject_guests(): void
    {
        $protected = [
            'admin.dashboard',
            'admin.requests.index',
            'admin.requests.pending',
            'admin.resellers.index',
            'admin.payments.index',
            'admin.settings.index',
            'admin.security.logs',
            'admin.tariffs.index',
            'admin.providers.index',
            'reseller.dashboard',
            'reseller.recharge.create',
            'reseller.recharge.index',
            'reseller.statement.index',
            'reseller.downline.index',
            'reseller.deposit.create',
        ];

        foreach ($protected as $name) {
            $response = $this->get(route($name));

            $this->assertContains(
                $response->status(),
                [301, 302, 401, 403],
                "{$name} was served to a guest with HTTP {$response->status()}.",
            );
        }
    }

    public function test_mobile_api_rejects_a_missing_or_wrong_token(): void
    {
        foreach ([[], ['token' => 'nope', 'deviceid' => 'nope']] as $payload) {
            $response = $this->postJson('/apiapp/balance', $payload);

            $response->assertOk();
            // The Android client reads these two keys to decide it is signed out.
            $this->assertSame(0, $response->json('success'));
            $this->assertSame(2, $response->json('status'));
        }
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get(route('reseller.login'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("object-src 'none'", (string) $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $csp);
        // A nonce rather than 'unsafe-inline' is what makes the policy worth having.
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[^']+'/", (string) $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", (string) $csp);
    }
}
