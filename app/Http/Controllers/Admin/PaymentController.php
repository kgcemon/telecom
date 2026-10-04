<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\WalletType;
use App\Models\BalanceTransfer;
use App\Models\PaymentRequest;
use App\Models\Reseller;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\Exceptions\WalletException;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Adding balance to resellers, returning it, and clearing deposit claims.
 *
 * Every movement goes through WalletService, so the transfer and both ledger
 * lines commit together under a row lock. The legacy version adjusted the two
 * balances with separate unlocked updates, which could credit a downline
 * without ever debiting the upline.
 */
class PaymentController
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $query = BalanceTransfer::query()
            ->with(['reseller:id,username,name', 'performedBy:id,username,name'])
            ->when($request->filled('reseller'), fn ($q) => $q->where('userid', $request->integer('reseller')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->betweenDates(
                $request->date('from')?->format('Y-m-d'),
                $request->date('to')?->format('Y-m-d'),
            );

        $totals = (clone $query)->getQuery()
            ->selectRaw('COALESCE(SUM(credit), 0) AS credits, COALESCE(SUM(debit), 0) AS debits')
            ->first();

        return view('admin.payments.index', [
            'payments' => $query->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'filters' => $request->only(['reseller', 'type', 'from', 'to']),
            'symbol'  => $this->settings->currencySymbol(),
            'totals'  => [
                'credits' => (float) ($totals->credits ?? 0),
                'debits'  => (float) ($totals->debits ?? 0),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.payments.create', [
            'symbol'  => $this->settings->currencySymbol(),
            'wallets' => $this->availableWallets(),
        ]);
    }

    /** Add balance to, or take it back from, a reseller. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reseller' => ['required', 'integer', 'exists:reseller,id'],
            'wallet'   => ['required', 'string', Rule::in(array_keys($this->availableWallets()))],
            'action'   => ['required', 'in:add,return'],
            'amount'   => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'remark'   => ['nullable', 'string', 'max:200'],
        ], [], [
            'reseller' => 'reseller account',
        ]);

        $actor = $request->attributes->get('current_reseller');
        $target = Reseller::findOrFail($validated['reseller']);
        $wallet = WalletType::from($validated['wallet']);
        $amount = (float) $validated['amount'];

        // Guard the direction: adding moves balance from the actor down to the
        // target, returning moves it back up.
        [$from, $to] = $validated['action'] === 'add'
            ? [$actor, $target]
            : [$target, $actor];

        $description = sprintf(
            '%s by %s%s',
            $validated['action'] === 'add' ? 'Balance added' : 'Balance returned',
            $actor->username,
            filled($validated['remark'] ?? null) ? ' — '.$validated['remark'] : '',
        );

        try {
            DB::transaction(function () use ($from, $to, $wallet, $amount, $description, $target, $actor, $validated): void {
                $this->wallet->transfer($from, $to, $wallet, $amount, $description, [
                    'type' => $validated['action'] === 'add' ? 'plus' : 'minus',
                ]);

                // Mirror the movement into pay_receive, which the legacy
                // reports and the mobile app both read.
                BalanceTransfer::create([
                    'userid'        => (int) $target->id,
                    'p_id'          => (int) $actor->id,
                    'trxid'         => strtoupper(bin2hex(random_bytes(5))),
                    'desc'          => $description,
                    'remark'        => $validated['remark'] ?? '',
                    'sender'        => (string) $actor->username,
                    'debit'         => $validated['action'] === 'add' ? 0 : $amount,
                    'credit'        => $validated['action'] === 'add' ? $amount : 0,
                    'account'       => (float) $target->fresh()->balanceFor($wallet),
                    'type'          => $wallet->value,
                    'transfer_type' => $validated['action'],
                    'idate'         => now()->format('Y-m-d'),
                    'date'          => now()->format('Y-m-d H:i:s'),
                ]);
            });
        } catch (WalletException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->audit->record($actor, 'payment.'.$validated['action'], $description, [
            'reseller_id' => (int) $target->id,
            'amount'      => $amount,
            'wallet'      => $wallet->value,
        ]);

        return back()->with('status', sprintf(
            '%s %s %s %s.',
            $this->settings->currencySymbol(),
            number_format($amount, 2),
            $validated['action'] === 'add' ? 'added to' : 'returned from',
            $target->username,
        ));
    }

    /** Deposit claims waiting to be verified. */
    public function requests(Request $request): View
    {
        return view('admin.payments.requests', [
            'requests' => PaymentRequest::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
                ->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'symbol'  => $this->settings->currencySymbol(),
            'filters' => $request->only(['status']),
        ]);
    }

    /**
     * Approve a deposit claim and credit the reseller.
     *
     * The row is claimed with a conditional update first, so two operators
     * approving the same claim cannot credit it twice.
     */
    public function approveRequest(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $actor = $request->attributes->get('current_reseller');

        $claimed = PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->where('status', 0)
            ->update(['status' => 1]);

        if ($claimed !== 1) {
            return back()->with('warning', 'That claim was already processed.');
        }

        $target = Reseller::find($paymentRequest->userid);

        if ($target === null) {
            return back()->with('error', 'The claiming account no longer exists.');
        }

        $amount = (float) $paymentRequest->amount;

        $this->wallet->credit(
            $target,
            WalletType::Main,
            $amount,
            sprintf('Deposit %s via %s approved by %s', $paymentRequest->trx_id, $paymentRequest->gatway, $actor->username),
            ['type' => 'plus'],
        );

        $this->audit->record($actor, 'payment.deposit_approved', sprintf(
            'Approved deposit %s of %s for %s',
            $paymentRequest->trx_id,
            number_format($amount, 2),
            $target->username,
        ), ['payment_request' => (int) $paymentRequest->id]);

        return back()->with('status', 'Deposit approved and balance credited.');
    }

    public function rejectRequest(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $rejected = PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->where('status', 0)
            ->update(['status' => 2]);

        if ($rejected !== 1) {
            return back()->with('warning', 'That claim was already processed.');
        }

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'payment.deposit_rejected',
            sprintf('Rejected deposit %s', $paymentRequest->trx_id),
            ['payment_request' => (int) $paymentRequest->id, 'reason' => $validated['reason'] ?? null],
        );

        return back()->with('status', 'Deposit claim rejected.');
    }

    /** Only offer wallets this deployment actually uses. */
    private function availableWallets(): array
    {
        $security = $this->settings->security();
        $wallets = [WalletType::Main->value => WalletType::Main->label()];

        if ($security->splitWalletsEnabled()) {
            $wallets[WalletType::Bank->value] = WalletType::Bank->label();
        }

        if ($security->driveWalletEnabled()) {
            $wallets[WalletType::Drive->value] = WalletType::Drive->label();
        }

        return $wallets;
    }
}
