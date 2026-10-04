<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\WalletType;
use App\Models\Reseller;
use App\Services\Settings\SettingsService;
use App\Support\Navigation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Supplies the shared chrome - branding, navigation, wallet figures, account
 * menu - to the panel layout.
 *
 * The legacy layout fetched all of this itself with inline queries in the
 * header template, which meant every page paid for them again and a template
 * edit could change a query. Resolving it once in a composer keeps the view
 * free of data access.
 */
class PanelComposer
{
    public function __construct(
        private readonly Navigation $navigation,
        private readonly SettingsService $settings,
    ) {}

    public function compose(View $view): void
    {
        $isAdminPanel = Str::startsWith(Route::currentRouteName() ?? '', 'admin.');
        $user = Auth::guard($isAdminPanel ? 'admin' : 'reseller')->user();

        $company = $this->settings->company();
        $brandName = $company->displayName();

        $view->with([
            'currentUser'   => $user,
            'isAdminPanel'  => $isAdminPanel,
            'brandName'     => $brandName,
            'brandInitials' => $this->initials($brandName),
            'userInitials'  => $this->initials($user?->displayName() ?? '?'),
            'roleLabel'     => $this->roleLabel($user),
            'navigation'    => $user instanceof Reseller
                ? ($isAdminPanel ? $this->navigation->forAdmin($user) : $this->navigation->forReseller($user))
                : collect(),
            'walletSummary' => $this->wallets($user),
            'logoutUrl'     => route($isAdminPanel ? 'admin.logout' : 'reseller.logout'),
            'profileUrl'    => $this->safeRoute($isAdminPanel ? 'admin.profile.edit' : 'reseller.profile.edit'),
            'securityUrl'   => $this->safeRoute($isAdminPanel ? 'admin.security.edit' : 'reseller.security.edit'),
        ]);
    }

    /**
     * Balances for the header strip.
     *
     * Only the wallets this deployment actually uses are shown: with the split
     * wallet system off there is no point displaying an always-zero bank
     * balance, and the legacy header showed all three regardless.
     */
    private function wallets(?Reseller $user): ?array
    {
        if (! $user instanceof Reseller || $user->isAdministrative()) {
            return null;
        }

        $security = $this->settings->security();
        $symbol = $this->settings->currencySymbol();

        $wallets = [WalletType::Main];

        if ($security->splitWalletsEnabled()) {
            $wallets[] = WalletType::Bank;
        }

        if ($security->driveWalletEnabled()) {
            $wallets[] = WalletType::Drive;
        }

        $out = [];

        foreach ($wallets as $wallet) {
            $out[$wallet->label()] = $symbol.' '.number_format((float) $user->balanceFor($wallet), 2);
        }

        return $out;
    }

    private function roleLabel(?Reseller $user): string
    {
        $level = $user?->level();

        if ($level === null) {
            return 'Guest';
        }

        // Prefer the operator's own wording from `level_list` when present.
        $custom = \App\Models\LevelDefinition::query()
            ->where('name', $level->value)
            ->value('real_name');

        return filled($custom) ? (string) $custom : $level->defaultLabel();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        return Str::upper(
            count($parts) === 1
                ? Str::substr($parts[0], 0, 2)
                : Str::substr($parts[0], 0, 1).Str::substr($parts[count($parts) - 1], 0, 1),
        );
    }

    /** Avoid blowing up the layout if an optional route is not registered. */
    private function safeRoute(string $name): string
    {
        return Route::has($name) ? route($name) : '#';
    }
}
