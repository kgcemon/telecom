<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Models\ManualGateway;
use App\Models\PaymentRequest;
use App\Models\Reseller;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Resellers claiming a deposit they have sent to one of the company numbers.
 *
 * A claim only records an intent - it never credits balance. An operator
 * verifies it under Payments, which is what actually moves money.
 */
class DepositController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Request $request): View
    {
        return view('reseller.deposit.create', [
            'gateways' => ManualGateway::query()->where('status', 1)->orderBy('id')->get(),
            'symbol'   => $this->settings->currencySymbol(),
            'user'     => $this->user($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'gateway' => ['required', 'integer', 'exists:gateway,id'],
            'trx_id'  => ['required', 'string', 'min:4', 'max:60'],
            'amount'  => ['required', 'numeric', 'min:1', 'max:1000000'],
            'sender'  => ['required', 'string', 'max:20'],
        ], [], [
            'trx_id' => 'transaction ID',
            'sender' => 'sending number',
        ]);

        $gateway = ManualGateway::findOrFail($validated['gateway']);

        // One claim per transaction id. Without this the same deposit could be
        // submitted repeatedly and approved more than once by different
        // operators looking at different rows.
        $exists = PaymentRequest::query()
            ->where('trx_id', $validated['trx_id'])
            ->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->with('error', 'That transaction ID has already been submitted.');
        }

        PaymentRequest::create([
            'userid'       => (string) $user->id,
            'gatway'       => (string) $gateway->name,
            'trx_id'       => $validated['trx_id'],
            'amount'       => (string) $validated['amount'],
            'fee'          => '0',
            'tota_amount'  => (string) $validated['amount'],
            'sender'       => $validated['sender'],
            'sender_name'  => (string) $user->displayName(),
            'ip'           => (string) $request->ip(),
            'status'       => 0,
            'idate'        => now()->format('Y-m-d'),
            'time'         => now()->format('H:i:s'),
        ]);

        $this->audit->record($user, 'deposit.claimed', sprintf(
            'Submitted deposit claim %s for %s via %s',
            $validated['trx_id'],
            number_format((float) $validated['amount'], 2),
            $gateway->name,
        ));

        return redirect()
            ->route('reseller.deposit.index')
            ->with('status', 'Deposit submitted. Your balance will be credited once it is verified.');
    }

    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('reseller.deposit.index', [
            'requests' => PaymentRequest::query()
                ->where('userid', (string) $user->id)
                ->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25)),
            'symbol' => $this->settings->currencySymbol(),
        ]);
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
