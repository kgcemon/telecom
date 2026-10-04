<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the response headers that make the browser enforce part of the app's
 * security for us. The legacy app sent none of these.
 *
 * The Content Security Policy uses a per-request nonce rather than
 * `'unsafe-inline'`. The two inline scripts the layout needs - the theme applied
 * before first paint, and nothing else - carry that nonce, so they run while any
 * script injected into a page through stored data does not. Allowing
 * `'unsafe-inline'` instead would have been one character shorter and would have
 * given away most of the protection.
 */
class SecurityHeaders
{
    /** Request attribute and view variable the nonce is published under. */
    public const NONCE_KEY = 'csp_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // Generated before the response is built so views can reference it.
        $nonce = base64_encode(random_bytes(16));

        $request->attributes->set(self::NONCE_KEY, $nonce);
        View::share(self::NONCE_KEY, $nonce);

        $response = $next($request);

        // File downloads (CSV exports, backups) get none of this.
        if ($response->headers->has('Content-Disposition')) {
            return $response;
        }

        $headers = [
            // Stop the browser second-guessing a declared content type, which
            // is what turns an uploaded file into executable script.
            'X-Content-Type-Options' => 'nosniff',

            // Deny framing: the panel moves money, so clickjacking matters.
            'X-Frame-Options' => 'DENY',

            // Do not leak panel URLs, which contain account ids, to third parties.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // The panel has no reason to reach the camera, mic or location.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy($nonce);
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * `'unsafe-eval'` is required because Alpine compiles its expressions with
     * `new Function`. It is the one concession here; inline scripts still need
     * the nonce, and `object-src 'none'` plus `base-uri 'self'` close the usual
     * ways around a policy.
     *
     * Styles keep `'unsafe-inline'`: the panel sets colours through inline
     * `style` attributes so that a single design token drives both themes, and
     * a style injection is not a script execution.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        $cdn = 'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' {$cdn}",
            "style-src 'self' 'unsafe-inline' {$cdn} https://fonts.googleapis.com",
            "font-src 'self' data: {$cdn} https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
