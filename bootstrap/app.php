<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAppToken;
use App\Http\Middleware\EnsurePanelAccess;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The admin panel, the reseller panel and the mobile API each have
            // their own file so the middleware that guards them is declared in
            // one place rather than repeated per controller.
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->name('reseller.')
                ->group(base_path('routes/reseller.php'));

            // Path kept as /apiapp so the installed Android client keeps working.
            Route::middleware('apiapp')
                ->prefix('apiapp')
                ->name('apiapp.')
                ->group(base_path('routes/apiapp.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'panel'       => EnsurePanelAccess::class,
            'can.feature' => RequirePermission::class,
            'app.token'   => EnsureAppToken::class,
        ]);

        // The mobile API is token-authenticated and stateless, so it gets
        // throttling and JSON handling but no session or CSRF.
        $middleware->group('apiapp', [
            'throttle:apiapp',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The Android client cannot read an HTML error page; always answer it
        // in the envelope it expects.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('apiapp/*') || $request->expectsJson(),
        );
    })
    ->create();
