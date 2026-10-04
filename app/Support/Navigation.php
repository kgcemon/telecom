<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Services\Security\PermissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar for whichever panel is being rendered.
 *
 * Each entry declares the permission it needs and is dropped when the signed-in
 * account lacks it. The same permission string is also attached to the route by
 * middleware - the sidebar hides the link, the middleware refuses the request.
 * Both are listed here so the two cannot drift apart.
 */
class Navigation
{
    public function __construct(private readonly PermissionService $permissions) {}

    /** @return Collection<string, Collection<int, array>> */
    public function forAdmin(Reseller $user): Collection
    {
        $pending = $this->pendingCount();

        return $this->build($user, [
            'Overview' => [
                ['admin.dashboard', 'Dashboard', 'home', null, null],
                ['admin.reports.index', 'Reports', 'chart', 'reports', null],
            ],
            'Transactions' => [
                ['admin.requests.pending', 'Pending Queue', 'clock', 'request_view', $pending ?: null],
                ['admin.requests.index', 'All Requests', 'list', 'request_view', null],
                ['admin.ledger.index', 'Ledger', 'receipt', 'reports', null],
            ],
            'Network' => [
                ['admin.resellers.index', 'Resellers', 'users', 'reseller_manage', null],
                ['admin.payments.index', 'Payments', 'wallet', 'payment_history', null],
                ['admin.tariffs.index', 'Tariffs & Rates', 'tag', 'server_rate', null],
            ],
            'Platform' => [
                ['admin.services.index', 'Services', 'box', 'server_module', null],
                ['admin.operators.index', 'Operators', 'phone', 'Oparetor_per', null],
                ['admin.routes.index', 'Routing', 'route', 'route', null],
                ['admin.providers.index', 'Providers', 'server', 'server_api', null],
            ],
            'Communication' => [
                ['admin.notices.index', 'Notices', 'bell', 'tools_notice', null],
                ['admin.tickets.index', 'Support Tickets', 'ticket', 'complain', null],
            ],
            'Security' => [
                ['admin.settings.index', 'Settings', 'settings', 'server_security', null],
                ['admin.security.devices', 'Devices', 'device', 'device_logs', null],
                ['admin.security.logs', 'Access Logs', 'shield', 'access_logs', null],
                ['admin.security.online', 'Online Now', 'eye', 'online_user', null],
            ],
        ]);
    }

    /** @return Collection<string, Collection<int, array>> */
    public function forReseller(Reseller $user): Collection
    {
        return $this->build($user, [
            'Overview' => [
                ['reseller.dashboard', 'Dashboard', 'home', null, null],
            ],
            'Recharge' => [
                ['reseller.recharge.create', 'New Recharge', 'phone', null, null],
                ['reseller.recharge.index', 'My Requests', 'list', null, null],
            ],
            'Balance' => [
                ['reseller.statement.index', 'Statement', 'receipt', null, null],
                ['reseller.deposit.create', 'Add Balance', 'wallet', null, null],
            ],
            'My Network' => [
                ['reseller.downline.index', 'My Resellers', 'users', null, null],
                ['reseller.rates.index', 'My Rates', 'tag', null, null],
            ],
            'Account' => [
                ['reseller.tickets.index', 'Support', 'ticket', null, null],
                ['reseller.profile.edit', 'Profile', 'user', null, null],
                ['reseller.security.edit', 'Password & PIN', 'lock', null, null],
            ],
        ]);
    }

    /**
     * @param  array<string, array<int, array{0:string,1:string,2:string,3:?string,4:?int}>>  $sections
     * @return Collection<string, Collection<int, array>>
     */
    private function build(Reseller $user, array $sections): Collection
    {
        $current = Route::currentRouteName() ?? '';

        return collect($sections)->map(
            fn (array $items) => collect($items)
                ->filter(function (array $item) use ($user) {
                    [, , , $permission] = $item;

                    return $permission === null || $this->permissions->allows($user, $permission);
                })
                // Drop entries whose route is not registered yet, so the sidebar
                // never renders a link that 404s.
                ->filter(fn (array $item) => Route::has($item[0]))
                ->map(fn (array $item) => [
                    'label'  => $item[1],
                    'icon'   => $item[2],
                    'url'    => route($item[0]),
                    'active' => $this->isActive($current, $item[0]),
                    'badge'  => $item[4],
                ])
                ->values(),
        );
    }

    /**
     * A link is active for its own route and for anything nested under it, so
     * "Resellers" stays highlighted while editing one.
     */
    private function isActive(string $current, string $target): bool
    {
        if ($current === $target) {
            return true;
        }

        $base = preg_replace('/\.(index|create|edit|show|pending)$/', '', $target);

        return $base !== $target && str_starts_with($current, $base.'.');
    }

    /** Badge count for the pending queue, cached briefly to keep it cheap. */
    private function pendingCount(): int
    {
        return Cache::remember('nav.pending-count', 30, fn () => RechargeRequest::query()
            ->whereIn('status', [TransactionStatus::Waiting->value, TransactionStatus::Pending->value])
            ->count());
    }
}
