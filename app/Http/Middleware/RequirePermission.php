<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Reseller;
use App\Services\Security\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a feature permission on a route.
 *
 * Usage: `->middleware('can.feature:server_rate')`, or several separated by
 * commas when any one of them is enough.
 *
 * This is the server-side counterpart to hiding a sidebar link. Both are
 * needed: hiding the link is presentation, this is the control.
 */
class RequirePermission
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->attributes->get('current_reseller')
            ?? Auth::guard('admin')->user()
            ?? Auth::guard('reseller')->user();

        $required = array_filter(array_map('trim', explode(',', $permissions)));

        if (! $user instanceof Reseller || ! $this->permissions->allowsAny($user, $required)) {
            $message = 'You do not have permission to use this feature.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
