<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Enums\WalletType;
use App\Models\BalanceTransfer;
use App\Models\Reseller;
use App\Services\Reseller\ResellerService;
use App\Services\Security\AuditLogger;
use App\Services\Security\PermissionService;
use App\Services\Security\PinService;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\Exceptions\WalletException;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A reseller managing the accounts directly beneath them.
 *
 * Every lookup is scoped through PermissionService::canManage(), so changing
 * the id in the URL cannot reach an account outside the signed-in reseller's
 * own subtree.
 */
class DownlineController
{
    public function __construct(
        private readonly ResellerService $resellers,
        private readonly PermissionService $permissions,
        private readonly WalletService $wallet,
        private readonly PinService $pins,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('reseller.downline.index', [
            'resellers' => Reseller::query()
                ->where('p_id', $user->id)
                ->where('delete', '!=', 1)
                ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')->toString()))
                ->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'user'    => $user,
            'symbol'  => $this->settings->currencySymbol(),
            'filters' => $request->only(['q']),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $this->user($request);

        abort_if($user->level()?->childLevel() === null, 403, 'Your level cannot create sub-accounts.');

        return view('reseller.downline.create', [
            'user'   => $user,
            'level'  => $user->level()?->childLevel(),
            'symbol' => $this->settings->currencySymbol(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $childLevel = $user->level()?->childLevel();

        abort_if($childLevel === null, 403, 'Your level cannot create sub-accounts.');

        $validated = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:60',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('reseller', 'username'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'pincode'  => ['required', 'string', 'min:4', 'max:12'],
            'name'     => ['required', 'string', 'max:120'],
            'mobile'   => ['required', 'string', 'max:20'],
            'email'    => ['nullable', 'email', 'max:120'],
        ]);

        $reseller = $this->resellers->create($user, $validated + [
            'custype'    => $childLevel->value,
            'webAccess'  => 1,
            'appsAccess' => 1,
        ]);

        $this->audit->record($user, 'downline.created', sprintf(
            'Created sub-account %s at level %s',
            $reseller->username,
            $childLevel->value,
        ), ['reseller_id' => (int) $reseller->id]);

        return redirect()
            ->route('reseller.downline.index')
            ->with('status', 'Account created. Share the credentials securely.');
    }

    public function edit(Request $request, Reseller $reseller): View
    {
        $this->authorise($request, $reseller);

        return view('reseller.downline.edit', [
            'reseller' => $reseller,
            'symbol'   => $this->settings->currencySymbol(),
        ]);
    }

    public function update(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authorise($request, $reseller);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'mobile'   => ['required', 'string', 'max:20'],
            'email'    => ['nullable', 'email', 'max:120'],
            'status'   => ['nullable', 'boolean'],
            'recharge' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100', 'confirmed'],
            'pincode'  => ['nullable', 'string', 'min:4', 'max:12'],
        ]);

        $this->resellers->update($reseller, $validated);

        $this->audit->record($this->user($request), 'downline.updated', sprintf(
            'Updated sub-account %s',
            $reseller->username,
        ), ['reseller_id' => (int) $reseller->id]);

        return back()->with('status', 'Account updated.');
    }

    /** Move balance to or from a sub-account, authorised by the PIN. */
    public function transferBalance(Request $request, Reseller $reseller): RedirectResponse
    {
        $user = $this->user($request);
        $this->authorise($request, $reseller);

        if (! $this->settings->security()->balanceTransferEnabled()) {
            return back()->with('error', 'Balance transfers are disabled on this platform.');
        }

        $validated = $request->validate([
            'action' => ['required', 'in:add,return'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'pin'    => ['required', 'string', 'max:20'],
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        // Moving money always requires the PIN, not just the session.
        [$pinOk, $pinError] = $this->pins->verify($user, $validated['pin']);

        if (! $pinOk) {
            return back()->withInput($request->except('pin'))->with('error', (string) $pinError);
        }

        $amount = (float) $validated['amount'];
        $wallet = WalletType::Main;

        [$from, $to] = $validated['action'] === 'add' ? [$user, $reseller] : [$reseller, $user];

        $description = sprintf(
            '%s %s %s',
            $validated['action'] === 'add' ? 'Transfer to' : 'Collected from',
            $reseller->username,
            filled($validated['remark'] ?? null) ? '— '.$validated['remark'] : '',
        );

        try {
            DB::transaction(function () use ($from, $to, $wallet, $amount, $description, $user, $reseller, $validated): void {
                $this->wallet->transfer($from, $to, $wallet, $amount, $description);

                BalanceTransfer::create([
                    'userid'        => (int) $reseller->id,
                    'p_id'          => (int) $user->id,
                    'trxid'         => strtoupper(bin2hex(random_bytes(5))),
                    'desc'          => $description,
                    'remark'        => $validated['remark'] ?? '',
                    'sender'        => (string) $user->username,
                    'debit'         => $validated['action'] === 'add' ? 0 : $amount,
                    'credit'        => $validated['action'] === 'add' ? $amount : 0,
                    'account'       => (float) $reseller->fresh()->balance,
                    'type'          => $wallet->value,
                    'transfer_type' => $validated['action'],
                    'idate'         => now()->format('Y-m-d'),
                    'date'          => now()->format('Y-m-d H:i:s'),
                ]);
            });
        } catch (WalletException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->record($user, 'downline.balance_'.$validated['action'], $description, [
            'reseller_id' => (int) $reseller->id,
            'amount'      => $amount,
        ]);

        return back()->with('status', sprintf(
            '%s %s %s.',
            $this->settings->currencySymbol(),
            number_format($amount, 2),
            $validated['action'] === 'add' ? 'transferred' : 'collected',
        ));
    }

    private function authorise(Request $request, Reseller $target): void
    {
        abort_unless(
            $this->permissions->canManage($this->user($request), $target),
            404,
        );
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
