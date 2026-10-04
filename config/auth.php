<?php

declare(strict_types=1);

use App\Models\Reseller;

/**
 * Authentication wiring.
 *
 * Administrators and resellers live in the same `reseller` table, separated by
 * `custype`, so they share one provider but get separate guards. Separate
 * guards mean separate session keys: signing in to the reseller panel does not
 * produce an admin session, which in the legacy app was only prevented by a
 * check at the top of each controller - and that check was missing from
 * several of them.
 */
return [

    'defaults' => [
        'guard' => 'reseller',
        'passwords' => 'resellers',
    ],

    'guards' => [

        /* Admin and sub-admin panel. */
        'admin' => [
            'driver' => 'session',
            'provider' => 'legacy-resellers',
        ],

        /* Reseller panel (the five distribution tiers). */
        'reseller' => [
            'driver' => 'session',
            'provider' => 'legacy-resellers',
        ],

        /*
         | Mobile app and machine API. Authenticated by the `apps_key` token the
         | Android client sends, handled by the EnsureAppToken middleware rather
         | than by a session.
         */
        'app' => [
            'driver' => 'session',
            'provider' => 'legacy-resellers',
        ],
    ],

    'providers' => [
        /*
         | Registered in AppServiceProvider. Verifies both the legacy SHA-1
         | chain format and bcrypt, and upgrades the former on login.
         */
        'legacy-resellers' => [
            'driver' => 'legacy-reseller',
        ],

        'resellers' => [
            'driver' => 'eloquent',
            'model' => Reseller::class,
        ],
    ],

    'passwords' => [
        'resellers' => [
            'provider' => 'legacy-resellers',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    /*
     | How long a password confirmation stays valid before a sensitive action
     | asks for it again. Three hours is the framework default; shortened here
     | because these sessions can move money.
     */
    'password_timeout' => 1800,
];
