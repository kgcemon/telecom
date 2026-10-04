<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reseller;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Loads every GET page in both panels as a signed-in user.
 *
 * This is a breadth check rather than a behaviour check: it catches undefined
 * variables, missing views, broken Blade components and bad route references
 * across ~30 screens, which is the class of error that otherwise only surfaces
 * when someone opens the page in production.
 */
class PanelSmokeTest extends TestCase
{
    /** Routes needing a model id, with a sample value filled in at runtime. */
    private const SKIP_PATTERNS = [
        'admin.dashboard.series',  // JSON endpoint, covered separately
        'admin.reports.export',    // streamed download
    ];

    public function test_every_admin_page_loads(): void
    {
        $admin = Reseller::query()->administrative()->active()->first();

        if ($admin === null) {
            $this->markTestSkipped('No administrative account exists in this database.');
        }

        [$failures, $visited] = $this->crawl('admin', $admin);

        // Guard against the crawl silently matching nothing, which would make
        // this test pass while checking zero pages.
        $this->assertGreaterThanOrEqual(15, $visited, 'The admin crawl visited too few pages to be meaningful.');
        $this->assertSame([], $failures, "Admin pages failed:\n".implode("\n", $failures));
    }

    public function test_every_reseller_page_loads(): void
    {
        $reseller = Reseller::query()
            ->active()
            ->whereNotIn('custype', ['admin', 'subadmin'])
            ->first();

        if ($reseller === null) {
            $this->markTestSkipped('No reseller account exists in this database.');
        }

        [$failures, $visited] = $this->crawl('reseller', $reseller);

        $this->assertGreaterThanOrEqual(8, $visited, 'The reseller crawl visited too few pages to be meaningful.');
        $this->assertSame([], $failures, "Reseller pages failed:\n".implode("\n", $failures));
    }

    /**
     * Visit every GET route in a panel and collect the ones that did not render.
     *
     * @return array{0: array<int, string>, 1: int} [failures, pages visited]
     */
    private function crawl(string $panel, Reseller $user): array
    {
        $failures = [];
        $visited = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, $panel.'.')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, self::SKIP_PATTERNS, true) || str_contains($name, 'login')) {
                continue;
            }

            $uri = $this->resolveUri($route->uri(), $user);

            if ($uri === null) {
                continue; // needs a model we cannot sample
            }

            $response = $this->actingAs($user, $panel)->get('/'.ltrim($uri, '/'));
            $visited++;

            // 403 is a legitimate answer when the sample account lacks the
            // permission; it still proves the route and middleware resolved.
            if (! in_array($response->status(), [200, 403, 302], true)) {
                $failures[] = sprintf(
                    '%s (%s) -> HTTP %d  %s',
                    $name,
                    $uri,
                    $response->status(),
                    $this->extractError($response->getContent()),
                );
            }
        }

        return [$failures, $visited];
    }

    /** Fill route parameters with a real id, or skip when none exists. */
    private function resolveUri(string $uri, Reseller $user): ?string
    {
        if (! str_contains($uri, '{')) {
            return $uri;
        }

        $samples = [
            'reseller'        => (string) $user->id,
            'rechargeRequest' => (string) (\App\Models\RechargeRequest::query()->value('id') ?? ''),
            'tariff'          => (string) (\App\Models\Tariff::query()->value('id') ?? ''),
            'service'         => (string) (\App\Models\Service::query()->value('id') ?? ''),
            'operator'        => (string) (\App\Models\Operator::query()->value('id') ?? ''),
            'route'           => (string) (\App\Models\Route::query()->value('id') ?? ''),
            'provider'        => (string) (\App\Models\ApiProvider::query()->value('id') ?? ''),
            'device'          => (string) (\App\Models\DeviceSession::query()->value('id') ?? ''),
            'ticket'          => (string) (\App\Models\Ticket::query()->value('id') ?? ''),
            'paymentRequest'  => (string) (\App\Models\PaymentRequest::query()->value('id') ?? ''),
        ];

        foreach ($samples as $parameter => $value) {
            if (! str_contains($uri, '{'.$parameter.'}')) {
                continue;
            }

            if ($value === '') {
                return null;
            }

            $uri = str_replace('{'.$parameter.'}', $value, $uri);
        }

        return str_contains($uri, '{') ? null : $uri;
    }

    /** Pull the first useful line out of an error page, for the failure message. */
    private function extractError(string $html): string
    {
        if (preg_match('/<title>(.*?)<\/title>/s', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return trim(mb_substr(strip_tags($html), 0, 180));
    }
}
