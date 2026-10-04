<?php

declare(strict_types=1);

use App\Services\Providers\EcareGateway;
use App\Services\Providers\GenericApiGateway;

/**
 * Platform configuration that belongs in code rather than in the database.
 *
 * Operational policy (limits, timeouts, auto-approval ceilings) stays in
 * `security_option` so operators can change it from the panel. What lives here
 * is wiring and defence-in-depth: which drivers exist, how long to wait on a
 * provider, and the hard ceilings that no database value may exceed.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Upstream recharge providers
    |--------------------------------------------------------------------------
    |
    | Keys are `api_set.provider` values. Provider 11 is the Ecare/ECT
    | integration; everything else speaks the platform's own /sendapi protocol.
    |
    */

    'providers' => [

        'default' => GenericApiGateway::class,

        'drivers' => [
            11 => EcareGateway::class,
        ],

        /*
         | Existing `api_set.url` rows hold bare IPs served over plain HTTP, so
         | this defaults to off to avoid breaking a live deployment. Turn it on
         | once providers are reachable over TLS - credentials and subscriber
         | numbers should not cross the network in clear text.
         */
        'force_https' => env('TELECOM_PROVIDER_FORCE_HTTPS', false),

        'connect_timeout' => env('TELECOM_PROVIDER_CONNECT_TIMEOUT', 5),
        'request_timeout' => env('TELECOM_PROVIDER_REQUEST_TIMEOUT', 30),

        /*
         | How long a request may sit in Processing before the status poller
         | treats it as stale and re-queries the provider.
         */
        'stale_after_minutes' => env('TELECOM_PROVIDER_STALE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hard limits
    |--------------------------------------------------------------------------
    |
    | Backstops that apply no matter what the database says. `security_option`
    | currently ships with a daily reseller limit of 5 000 000 and a manual-route
    | ceiling of the same, which in practice disables both checks; these caps
    | mean a mistaken or malicious settings change cannot open the system up
    | without a code deploy.
    |
    */

    'limits' => [
        'max_single_request'    => env('TELECOM_MAX_SINGLE_REQUEST', 50000),
        'max_daily_per_reseller' => env('TELECOM_MAX_DAILY_PER_RESELLER', 1000000),
        'max_bulk_rows'         => env('TELECOM_MAX_BULK_ROWS', 500),

        /*
         | Minimum seconds between two identical requests (same reseller, same
         | number, same amount). Guards against a double-tap in the app turning
         | into two top-ups.
         */
        'duplicate_window_seconds' => env('TELECOM_DUPLICATE_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        /* Failed logins allowed per username+IP before a lockout. */
        'max_attempts'     => env('TELECOM_AUTH_MAX_ATTEMPTS', 5),
        'lockout_minutes'  => env('TELECOM_AUTH_LOCKOUT_MINUTES', 15),

        /* Wrong PINs allowed before the account is frozen for review. */
        'max_pin_attempts' => env('TELECOM_AUTH_MAX_PIN_ATTEMPTS', 5),

        /* Mobile-app tokens expire after this long without use. */
        'app_token_lifetime_days' => env('TELECOM_APP_TOKEN_DAYS', 30),

        'bcrypt_rounds' => env('TELECOM_BCRYPT_ROUNDS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'currency_symbol' => env('TELECOM_CURRENCY', 'Tk'),
        'per_page'        => env('TELECOM_PER_PAGE', 25),
        'timezone'        => env('APP_TIMEZONE', 'Asia/Dhaka'),
    ],
];
