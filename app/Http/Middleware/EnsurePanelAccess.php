<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ResellerLevel;
use App\Models\Reseller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a whole panel: the visitor must be signed in on the right guard, with
 * an active account and an appropriate role.
 *
 * Applied to the admin route group as a whole rather than per controller. The
 * legacy app did this check at the top of individual controller methods and
 * missed it in several, which left admin pages reachable from a reseller
 * session.
 */
class EnsurePanelAccess
{
    public function handle(Request $request, Closure $next, string $panel = 'reseller'): Response
    {
        $guard = $panel === 'admin' ? 'admin' : 'reseller';
        $user = Auth::guard($guard)->user();

        if (! $user instanceof Reseller) {
            return $this->reject($request, $panel, 'Please sign in to continue.');
        }

        if (! $user->isActive() || (int) $user->login_allow !== 1) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->reject($request, $panel, 'This account is no longer active.');
        }

        $level = $user->level();

        if ($level === null) {
            Auth::guard($guard)->logout();

            return $this->reject($request, $panel, 'This account has no role assigned.');
        }

        // The admin panel is for administrative roles only. The reseller panel
        // accepts any role, so an admin can see what their resellers see.
        if ($panel === 'admin' && ! $level->isAdministrative()) {
            return $this->reject($request, 'reseller', 'You do not have access to the admin panel.', 403);
        }

        // Make the resolved account available without re-querying it.
        $request->attributes->set('current_reseller', $user);

        return $next($request);
    }

    private function reject(Request $request, string $panel, string $message, int $status = 401): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        $route = $panel === 'admin' ? 'admin.login' : 'reseller.login';

        return redirect()->route($route)->withErrors(['username' => $message]);
    }
}
