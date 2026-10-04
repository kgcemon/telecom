<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\ApiProvider;
use App\Services\Providers\ProviderManager;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Upstream provider credentials and balances.
 *
 * API keys are write-only in this screen: the stored value is never rendered
 * back into the form, and submitting the field blank leaves it unchanged. The
 * legacy page printed the key straight into a text input, so anyone who could
 * open the page - or a stored-XSS payload running in it - could read every
 * provider credential.
 */
class ProviderController
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.providers.index', [
            'providers' => ApiProvider::query()->orderBy('api_title')->get(),
            'drivers'   => $this->providers->available(),
            'symbol'    => $this->settings->currencySymbol(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_title' => ['required', 'string', 'max:120'],
            'provider'  => ['required', 'integer'],
            'url'       => ['required', 'string', 'max:200'],
            'port'      => ['nullable', 'string', 'max:10'],
            'userid'    => ['required', 'string', 'max:120'],
            'api_key'   => ['required', 'string', 'max:200'],
            'bal_url'   => ['nullable', 'string', 'max:200'],
            'resurl'    => ['nullable', 'string', 'max:200'],
            'status'    => ['nullable', 'boolean'],
        ], [], [
            'provider' => 'driver',
            'api_key'  => 'API key',
        ]);

        $validated['status'] = ! empty($validated['status']) ? 1 : 0;

        $provider = ApiProvider::create($validated + [
            'balance' => 0,
            'time'    => now()->format('H:i:s'),
            'date'    => now()->format('Y-m-d'),
            'response' => '',
            'bal_response' => '',
        ]);

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'provider.created',
            sprintf('Added provider %s', $provider->api_title),
        );

        return back()->with('status', 'Provider added.');
    }

    public function update(Request $request, ApiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'api_title' => ['required', 'string', 'max:120'],
            'provider'  => ['required', 'integer'],
            'url'       => ['required', 'string', 'max:200'],
            'port'      => ['nullable', 'string', 'max:10'],
            'userid'    => ['required', 'string', 'max:120'],
            'api_key'   => ['nullable', 'string', 'max:200'],
            'bal_url'   => ['nullable', 'string', 'max:200'],
            'resurl'    => ['nullable', 'string', 'max:200'],
            'status'    => ['nullable', 'boolean'],
        ]);

        $validated['status'] = ! empty($validated['status']) ? 1 : 0;

        // Blank means "keep the existing key" rather than "clear it".
        if (blank($validated['api_key'] ?? null)) {
            unset($validated['api_key']);
        }

        $provider->forceFill($validated)->save();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'provider.updated',
            sprintf('Updated provider %s', $provider->api_title),
            ['key_rotated' => array_key_exists('api_key', $validated)],
        );

        return back()->with('status', 'Provider updated.');
    }

    /** Ask the provider for its current balance. */
    public function refreshBalance(Request $request, ApiProvider $provider): RedirectResponse
    {
        $balance = $this->providers->for($provider)->balance($provider);

        if ($balance === null) {
            return back()->with('warning', 'This provider did not return a balance.');
        }

        $provider->forceFill(['balance' => $balance])->save();

        return back()->with('status', sprintf(
            'Balance updated: %s %s',
            $this->settings->currencySymbol(),
            number_format($balance, 2),
        ));
    }
}
