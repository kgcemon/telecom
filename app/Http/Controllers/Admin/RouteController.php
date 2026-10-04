<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\ApiProvider;
use App\Models\Operator;
use App\Models\Route as RouteModel;
use App\Models\Service;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Routing: which provider fulfils which service and operator.
 *
 * The listing is ordered the same way the engine picks a route - specific
 * operator before the catch-all, then ascending priority - so what an operator
 * sees at the top of the list is genuinely what will be used. The legacy screen
 * listed by id while the engine effectively used the last row, so the two
 * disagreed.
 */
class RouteController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.routes.index', [
            'routes' => RouteModel::query()
                ->orderBy('service')
                ->orderByRaw("CASE WHEN pcode = 'all' THEN 1 ELSE 0 END")
                ->orderBy('priority')
                ->get(),
            'services'  => Service::query()->orderBy('title')->get(['serviceid', 'title']),
            'providers' => ApiProvider::query()->orderBy('api_title')->get(['id', 'api_title', 'status']),
            'operators' => Operator::query()->whereNotNull('ex')->orderBy('title_op')->get(['ex', 'title_op']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRoute($request);

        RouteModel::create($validated + [
            'date' => now()->format('Y-m-d'),
            'time' => now()->format('H:i:s'),
        ]);

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'route.created',
            sprintf('Added route %s for service %s', $validated['title'], $validated['service']),
        );

        return back()->with('status', 'Route added.');
    }

    public function update(Request $request, RouteModel $route): RedirectResponse
    {
        $route->forceFill($this->validateRoute($request))->save();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'route.updated',
            sprintf('Updated route %s', $route->title),
        );

        return back()->with('status', 'Route updated.');
    }

    public function destroy(Request $request, RouteModel $route): RedirectResponse
    {
        $title = (string) $route->title;
        $route->delete();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'route.deleted',
            sprintf('Deleted route %s', $title),
        );

        return back()->with('status', 'Route deleted.');
    }

    private function validateRoute(Request $request): array
    {
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:120'],
            'route'    => ['required', 'string', 'max:60'],
            'service'  => ['required', 'integer', 'exists:module,serviceid'],
            'pcode'    => ['required', 'string', 'max:30'],
            'priority' => ['required', 'integer', 'min:0', 'max:99'],
            'active'   => ['nullable', 'boolean'],
        ], [], [
            'route'    => 'provider',
            'pcode'    => 'operator',
            'priority' => 'priority (lower wins)',
        ]);

        $validated['active'] = ! empty($validated['active']) ? 1 : 0;
        // The legacy encoding of "usable" is active = 1 together with status = 0.
        $validated['status'] = 0;

        return $validated;
    }
}
