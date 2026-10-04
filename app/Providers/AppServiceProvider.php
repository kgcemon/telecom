<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Security\LegacyHasher;
use App\Services\Security\LegacyUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Auth;
use App\View\Composers\PanelComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One bcrypt cost for the whole application, including the hasher that
        // LegacyHasher wraps, so "needs rehash" is judged consistently.
        $this->app->bind(Hasher::class, fn () => new BcryptHasher([
            'rounds' => (int) config('telecom.auth.bcrypt_rounds', 12),
        ]));

        $this->app->singleton(LegacyHasher::class, fn ($app) => new LegacyHasher(
            $app->make(Hasher::class),
        ));
    }

    public function boot(): void
    {
        Auth::provider('legacy-reseller', fn ($app) => new LegacyUserProvider(
            $app->make(LegacyHasher::class),
        ));

        // Shared panel chrome: branding, navigation, wallet figures, account menu.
        View::composer('layouts.panel', PanelComposer::class);

        $this->registerRateLimiters();

        // Fail loudly in development when a view or report touches a relation
        // that was not eager-loaded. The legacy reports issued thousands of
        // queries per page; this keeps that from creeping back in.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Honour the proxy's scheme so generated URLs stay https behind a
        // load balancer or cPanel reverse proxy.
        if ($this->app->isProduction() || config('telecom.force_https', false)) {
            URL::forceScheme('https');
        }
    }

    /**
     * Request-rate limits.
     *
     * The legacy app had none, which is why `login_attempts` grew to 90 000
     * rows: nothing ever slowed an attacker down. These are deliberately
     * keyed on more than the IP, because a single mobile operator NAT puts
     * thousands of legitimate app users behind one address.
     */
    private function registerRateLimiters(): void
    {
        // Sign-in: per username+IP, so one noisy network cannot lock out
        // everybody, while a single account still cannot be hammered.
        RateLimiter::for('logins', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-user:'.Str::lower((string) $request->input('username'))),
        ]);

        // Placing a recharge: generous enough for a busy retail counter,
        // tight enough that a scripted loop is stopped.
        RateLimiter::for('recharge', fn (Request $request) => Limit::perMinute(30)
            ->by('recharge:'.($request->user()?->id ?? $request->ip())));

        // Mobile API: keyed on the app token when present, so one device
        // cannot spend another device's allowance.
        RateLimiter::for('apiapp', function (Request $request) {
            $token = (string) ($request->input('token') ?: $request->header('X-App-Token', ''));

            return $token !== ''
                ? Limit::perMinute(90)->by('apiapp-token:'.sha1($token))
                : Limit::perMinute(30)->by('apiapp-ip:'.$request->ip());
        });
    }
}
