<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\ResellerLevel;
use App\Models\MenuPermission;
use App\Models\Reseller;
use Illuminate\Support\Facades\Cache;

/**
 * Feature permissions, read from the legacy `menu_list` table.
 *
 * `menu_list` stores one integer column per permission - 47 of them - which is
 * awkward but is what the live data uses, so it stays. What changes is that the
 * flags are now consulted centrally and are the only thing that grants access.
 * In the legacy panel the sidebar hid links a sub-admin lacked, but the
 * controllers behind those links did not check anything, so the feature was
 * still reachable by typing the URL. Hiding a link is not access control; the
 * middleware that uses this service is.
 */
class PermissionService
{
    /**
     * Permission key => [group, human label].
     *
     * The key is the `menu_list` column name. Grouping drives the permission
     * editor and the sidebar.
     */
    public const PERMISSIONS = [
        'request_view'       => ['Requests', 'View requests'],
        'request_confirm'    => ['Requests', 'Approve requests'],
        'request_cancel'     => ['Requests', 'Cancel and refund'],
        'request_resend'     => ['Requests', 'Resend to provider'],
        'request_details'    => ['Requests', 'View request details'],

        'prepaid_history'    => ['Prepaid Cards', 'Card history'],
        'prepaid_add'        => ['Prepaid Cards', 'Add card stock'],
        'prepaid_manage'     => ['Prepaid Cards', 'Manage cards'],

        'billpay_history'    => ['Bill Pay', 'Bill pay history'],
        'billpay_settings'   => ['Bill Pay', 'Bill pay settings'],

        'bank_history'       => ['Banking', 'Bank transfer history'],
        'bank_settings'      => ['Banking', 'Bank settings'],

        'sms_view'           => ['Messaging', 'View SMS'],
        'sms_delete'         => ['Messaging', 'Delete SMS'],
        'message_history'    => ['Messaging', 'Message history'],
        'message_outbox'     => ['Messaging', 'Outbox'],
        'message_settings'   => ['Messaging', 'Message settings'],

        'reseller_manage'    => ['Resellers', 'Manage resellers'],
        'reseller_add'       => ['Resellers', 'Create resellers'],
        'reseller_edit'      => ['Resellers', 'Edit resellers'],
        'server_delete_res'  => ['Resellers', 'Delete resellers'],

        'payment_history'    => ['Payments', 'Payment history'],
        'payment_add_return' => ['Payments', 'Add and return balance'],
        'pay_log'            => ['Payments', 'Payment logs'],
        'getway_set'         => ['Payments', 'Gateway settings'],

        'server_module'      => ['Platform', 'Service modules'],
        'server_rate'        => ['Platform', 'Rates and pricing'],
        'server_api'         => ['Platform', 'Provider APIs'],
        'route'              => ['Platform', 'Routing'],
        'server_security'    => ['Platform', 'Security settings'],

        'tarif_per'          => ['Catalogue', 'Tariffs'],
        'country_per'        => ['Catalogue', 'Countries'],
        'Oparetor_per'       => ['Catalogue', 'Operators'],
        'internet_per'       => ['Catalogue', 'Internet packages'],

        'tools_branding'     => ['Tools', 'Branding'],
        'tools_latest_update'=> ['Tools', 'Updates'],
        'tools_notice'       => ['Tools', 'Notices'],
        'tools_dbtool'       => ['Tools', 'Database tools'],

        'reports'            => ['Reports', 'Reports'],
        'complain'           => ['Support', 'Complaints and tickets'],

        'device_logs'        => ['Security', 'Device logs'],
        'ip_restec'          => ['Security', 'IP restrictions'],
        'online_user'        => ['Security', 'Online users'],
        'access_logs'        => ['Security', 'Access logs'],

        'api_info'           => ['Account', 'API information'],
        'profile'            => ['Account', 'Profile'],
    ];

    private const CACHE_TTL = 300;

    /** True when the account may use the given feature. */
    public function allows(?Reseller $user, string $permission): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }

        // A full administrator is not constrained by the per-feature flags;
        // every other level, including sub-admins, is.
        if ($user->level() === ResellerLevel::Admin) {
            return true;
        }

        if (! array_key_exists($permission, self::PERMISSIONS)) {
            // Unknown permission keys deny rather than allow, so a typo in a
            // route definition cannot accidentally open a feature up.
            return false;
        }

        return (int) ($this->flagsFor($user)[$permission] ?? 0) === 1;
    }

    public function denies(?Reseller $user, string $permission): bool
    {
        return ! $this->allows($user, $permission);
    }

    /** True when every listed permission is granted. */
    public function allowsAll(?Reseller $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** True when at least one listed permission is granted. */
    public function allowsAny(?Reseller $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$actor` may act on `$target`.
     *
     * Two conditions: the actor must outrank the target, and the target must be
     * inside the actor's own subtree. The legacy panel checked neither, so a
     * sub-admin could edit, re-price or take balance from an account under a
     * different sub-admin simply by changing the id in the URL.
     */
    public function canManage(?Reseller $actor, ?Reseller $target): bool
    {
        if ($actor === null || $target === null) {
            return false;
        }

        if ((int) $actor->id === (int) $target->id) {
            return true;
        }

        $actorLevel = $actor->level();
        $targetLevel = $target->level();

        if ($actorLevel === null || $targetLevel === null) {
            return false;
        }

        if ($actorLevel === ResellerLevel::Admin) {
            return true;
        }

        if ($actorLevel->depth() >= $targetLevel->depth()) {
            return false;
        }

        return in_array((int) $actor->id, array_map('intval', $target->ancestorIds()), true);
    }

    /** Permissions grouped for the permission editor UI. */
    public function grouped(): array
    {
        $groups = [];

        foreach (self::PERMISSIONS as $key => [$group, $label]) {
            $groups[$group][$key] = $label;
        }

        return $groups;
    }

    /** Current flags for an account, for rendering the editor. */
    public function flagsFor(Reseller $user): array
    {
        return Cache::remember(
            $this->cacheKey((int) $user->id),
            self::CACHE_TTL,
            function () use ($user): array {
                $row = MenuPermission::query()
                    ->where('userid', $user->id)
                    ->orderByDesc('id')
                    ->first();

                if ($row === null) {
                    return $this->defaultsFor($user);
                }

                $flags = [];

                foreach (array_keys(self::PERMISSIONS) as $key) {
                    $flags[$key] = (int) ($row->{$key} ?? 0);
                }

                return $flags;
            },
        );
    }

    /** Persist a permission set for an account. */
    public function sync(Reseller $user, array $granted): void
    {
        $payload = ['userid' => (int) $user->id, 'status' => 1];

        foreach (array_keys(self::PERMISSIONS) as $key) {
            $payload[$key] = in_array($key, $granted, true) ? 1 : 0;
        }

        $existing = MenuPermission::query()->where('userid', $user->id)->orderByDesc('id')->first();

        if ($existing !== null) {
            $existing->forceFill($payload)->save();
        } else {
            MenuPermission::create($payload);
        }

        $this->forget((int) $user->id);
    }

    public function forget(int $resellerId): void
    {
        Cache::forget($this->cacheKey($resellerId));
    }

    /**
     * Sensible starting permissions for an account with no row yet.
     *
     * A sub-admin gets the operational set but not platform configuration;
     * a reseller gets only their own account views. Defaulting to "nothing"
     * for unknown levels keeps a misconfigured account harmless.
     */
    private function defaultsFor(Reseller $user): array
    {
        $level = $user->level();

        $keys = match (true) {
            $level === ResellerLevel::Admin => array_keys(self::PERMISSIONS),
            $level === ResellerLevel::SubAdmin => [
                'request_view', 'request_confirm', 'request_cancel', 'request_details',
                'reseller_manage', 'reseller_add', 'reseller_edit',
                'payment_history', 'payment_add_return',
                'reports', 'complain', 'online_user', 'access_logs',
                'api_info', 'profile', 'tools_notice',
                'bank_history', 'billpay_history', 'prepaid_history', 'message_history',
            ],
            $level !== null => ['request_view', 'request_details', 'reports', 'api_info', 'profile'],
            default => [],
        };

        $flags = [];

        foreach (array_keys(self::PERMISSIONS) as $key) {
            $flags[$key] = in_array($key, $keys, true) ? 1 : 0;
        }

        return $flags;
    }

    private function cacheKey(int $resellerId): string
    {
        return 'permissions:'.$resellerId;
    }
}
