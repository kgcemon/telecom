<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\ManualGateway;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform settings.
 *
 * The legacy security page presented 32 raw database columns with their column
 * names as labels - `bkash_auto`, `rlverify`, `pass_stong`, `zip`, `city` - and
 * no indication of what any of them did or what units they were in. Operators
 * understandably left them alone, which is why the shipped configuration has a
 * daily limit of 5 000 000 (effectively unlimited).
 *
 * Each field here carries a plain-language label, an explanation of what
 * changing it does, and its unit. Nothing is renamed in the database, so the
 * legacy application keeps working against the same rows.
 */
class SettingsController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'company'  => $this->settings->company(),
            'security' => $this->settings->security(),
            'gateways' => ManualGateway::query()->orderBy('id')->get(),
            'symbol'   => $this->settings->currencySymbol(),
        ]);
    }

    /** Identity and contact details. */
    public function updateCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name'    => ['required', 'string', 'max:60'],
            'company_title'   => ['nullable', 'string', 'max:100'],
            'website'         => ['nullable', 'url', 'max:255'],
            'address'         => ['nullable', 'string', 'max:160'],
            'city'            => ['nullable', 'string', 'max:60'],
            'contact_no1'     => ['nullable', 'string', 'max:14'],
            'helpdesk_mobile' => ['nullable', 'string', 'max:14'],
            'helpdesk_email'  => ['nullable', 'email', 'max:60'],
            'footer'          => ['nullable', 'string', 'max:255'],
            'whatsapp'        => ['nullable', 'string', 'max:500'],
            'telegram'        => ['nullable', 'string', 'max:500'],
        ]);

        $this->settings->updateCompany($validated);

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'settings.company',
            'Updated company details',
            ['fields' => array_keys($validated)],
        );

        return back()->with('status', 'Company details saved.');
    }

    /**
     * Authentication and session policy.
     */
    public function updateSecurity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'admin_capcha'     => ['nullable', 'boolean'],
            'reseller_capcha'  => ['nullable', 'boolean'],
            'pass_stong'       => ['nullable', 'boolean'],
            'OTP'              => ['nullable', 'boolean'],
            'min_pin'          => ['required', 'integer', 'min:4', 'max:12'],
            'pass_exp'         => ['required', 'integer', 'min:0', 'max:3650'],
            'pin_exp'          => ['required', 'integer', 'min:0', 'max:3650'],
            'session_time_out' => ['required', 'integer', 'min:5', 'max:1440'],
        ], [], [
            'min_pin'          => 'minimum PIN length',
            'pass_exp'         => 'password expiry',
            'pin_exp'          => 'PIN expiry',
            'session_time_out' => 'session timeout',
        ]);

        $this->settings->updateSecurity($this->normaliseBooleans($validated, [
            'admin_capcha', 'reseller_capcha', 'pass_stong', 'OTP',
        ]));

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'settings.security',
            'Updated security policy',
            ['fields' => array_keys($validated)],
        );

        return back()->with('status', 'Security policy saved.');
    }

    /**
     * Transaction limits.
     *
     * These are the figures that decide what goes out automatically and what
     * waits for a human, so each one is explained in the view. The hard caps in
     * config/telecom.php still apply on top: a value entered here cannot widen
     * a limit beyond the compiled ceiling.
     */
    public function updateLimits(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'req_Interval'         => ['required', 'integer', 'min:0', 'max:120'],
            'reseller_dayli_limit' => ['required', 'integer', 'min:0'],
            'bkash_auto'           => ['required', 'integer', 'min:0'],
            'bulk_flexi_limit'     => ['required', 'integer', 'min:0', 'max:5000'],
            'reseller_OverPayment' => ['nullable', 'boolean'],
        ], [], [
            'req_Interval'         => 'duplicate request window',
            'reseller_dayli_limit' => 'daily reseller limit',
            'bkash_auto'           => 'manual route auto-dispatch ceiling',
            'bulk_flexi_limit'     => 'bulk flexiload row limit',
        ]);

        $this->settings->updateSecurity($this->normaliseBooleans($validated, ['reseller_OverPayment']));

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'settings.limits',
            'Updated transaction limits',
            $validated,
        );

        return back()->with('status', 'Transaction limits saved.');
    }

    /**
     * Which wallet funds which service, plus the on-premise modem switch.
     */
    public function updateWallets(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'balance_system' => ['nullable', 'boolean'],
            'drive'          => ['nullable', 'boolean'],
            'bal_transfer'   => ['nullable', 'boolean'],
            'modem'          => ['nullable', 'boolean'],
            'com_system'     => ['required', 'integer', 'in:0,1,2'],
        ], [], [
            'balance_system' => 'separate bank wallet',
            'drive'          => 'drive wallet',
            'bal_transfer'   => 'reseller-to-reseller transfers',
            'com_system'     => 'commission mode',
        ]);

        $this->settings->updateSecurity($this->normaliseBooleans($validated, [
            'balance_system', 'drive', 'bal_transfer', 'modem',
        ]));

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'settings.wallets',
            'Updated wallet and commission configuration',
            $validated,
        );

        return back()->with('status', 'Wallet configuration saved.');
    }

    /** Manual deposit channels resellers send money to. */
    public function updateDeposits(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gateways'             => ['array'],
            'gateways.*.id'        => ['required', 'integer', 'exists:gateway,id'],
            'gateways.*.number'    => ['nullable', 'string', 'max:11'],
            'gateways.*.status'    => ['nullable', 'boolean'],
            'gateways.*.note'      => ['nullable', 'string', 'max:500'],
            'gateways.*.tolerance' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        foreach ($validated['gateways'] ?? [] as $row) {
            ManualGateway::query()->where('id', $row['id'])->update([
                'number'    => $row['number'] ?? '',
                'status'    => ! empty($row['status']) ? 1 : 0,
                'note'      => $row['note'] ?? '',
                'tolerance' => (string) ($row['tolerance'] ?? 0),
            ]);
        }

        $this->settings->forget();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'settings.deposits',
            'Updated manual deposit channels',
        );

        return back()->with('status', 'Deposit channels saved.');
    }

    /**
     * Unchecked checkboxes are simply absent from the request, so every boolean
     * has to be explicitly defaulted to 0 or toggling one off would silently
     * leave it on.
     */
    private function normaliseBooleans(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $data[$key] = ! empty($data[$key]) ? 1 : 0;
        }

        return $data;
    }
}
