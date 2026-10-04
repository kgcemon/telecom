<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ResellerLevel;
use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\BalanceTransfer;
use App\Models\ManualGateway;
use App\Models\PaymentRequest;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Recharge\PricingService;
use App\Services\Reporting\DashboardMetrics;
use App\Services\Reseller\ResellerService;
use App\Services\Security\AuditLogger;
use App\Services\Security\LegacyHasher;
use App\Services\Security\PermissionService;
use App\Services\Security\PinService;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\Exceptions\WalletException;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account, balance, downline and support endpoints for the mobile app.
 *
 * Balance movements and account creation reuse the same services as the panel,
 * so the app cannot take a shortcut around a limit or a lock.
 */
class AppProfileController
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PricingService $pricing,
        private readonly ResellerService $resellers,
        private readonly PermissionService $permissions,
        private readonly PinService $pins,
        private readonly LegacyHasher $hasher,
        private readonly DashboardMetrics $metrics,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /apiapp/balance */
    public function balance(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $security = $this->settings->security();

        return response()->json([
            'success'      => 1,
            'status'       => 1,
            'balance'      => number_format((float) $user->balance, 2, '.', ''),
            'bank_balance' => $security->splitWalletsEnabled()
                ? number_format((float) $user->bank_balance, 2, '.', '')
                : '0.00',
            'drive_balance' => $security->driveWalletEnabled()
                ? number_format((float) $user->drive_bal, 2, '.', '')
                : '0.00',
            'limit'    => number_format((float) $user->balance_limit, 2, '.', ''),
            'username' => (string) $user->username,
            'name'     => $user->displayName(),
            'level'    => $user->level()?->defaultLabel() ?? '',
            'mobile'   => (string) $user->mobile,
            'email'    => (string) $user->email,
        ]);
    }

    /** GET /apiapp/myrate - what this account pays. */
    public function myRates(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $reference = 100.0;

        $samples = [
            'GP' => '01712345678', 'RB' => '01812345678', 'BL' => '01912345678',
            'AT' => '01612345678', 'TT' => '01512345678', 'SK' => '11312345678',
        ];

        $rows = [];

        foreach (Service::query()->enabled()->ordered()->get() as $service) {
            $type = $service->type();

            if ($type === null) {
                continue;
            }

            foreach ($samples as $code => $number) {
                $quote = $this->pricing->quote(
                    reseller: $user, service: $type, amount: $reference,
                    number: $number, numberType: '1', declaredOperator: $code,
                );

                if (! $quote->resolved) {
                    continue;
                }

                $rows[] = [
                    'service'    => $type->label(),
                    'operator'   => $code,
                    'commission' => number_format($quote->commissionPercent, 2, '.', ''),
                    'charge'     => number_format($quote->chargePercent, 2, '.', ''),
                    'per_100'    => number_format($quote->payable(), 2, '.', ''),
                ];
            }
        }

        return response()->json(['success' => 1, 'status' => 1, 'rate' => $rows]);
    }

    /** GET /apiapp/myResellerRates */
    public function resellerRates(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $children = Reseller::query()
            ->where('p_id', $user->id)
            ->where('delete', '!=', 1)
            ->get(['id', 'username', 'name', 'custype', 'tarif', 'balance']);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'rate'    => $children->map(fn (Reseller $child) => [
                'id'       => (int) $child->id,
                'username' => (string) $child->username,
                'name'     => $child->displayName(),
                'level'    => $child->level()?->defaultLabel() ?? '',
                'balance'  => number_format((float) $child->balance, 2, '.', ''),
            ])->values(),
        ]);
    }

    /** GET /apiapp/reseller - the accounts beneath this one. */
    public function downline(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'success'  => 1,
            'status'   => 1,
            'reseller' => Reseller::query()
                ->where('p_id', $user->id)
                ->where('delete', '!=', 1)
                ->orderByDesc('id')
                ->limit(300)
                ->get(['id', 'username', 'name', 'mobile', 'custype', 'balance', 'status'])
                ->map(fn (Reseller $r) => [
                    'id'       => (int) $r->id,
                    'username' => (string) $r->username,
                    'name'     => $r->displayName(),
                    'mobile'   => (string) $r->mobile,
                    'level'    => $r->level()?->defaultLabel() ?? '',
                    'balance'  => number_format((float) $r->balance, 2, '.', ''),
                    'status'   => (int) $r->status,
                ])->values(),
        ]);
    }

    /** POST /apiapp/reselleradd */
    public function createDownline(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $childLevel = $user->level()?->childLevel();

        if ($childLevel === null) {
            return $this->fail('Your level cannot create sub-accounts.');
        }

        $validator = validator($request->all(), [
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:reseller,username'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'pincode'  => ['required', 'string', 'min:4', 'max:12'],
            'name'     => ['required', 'string', 'max:120'],
            'mobile'   => ['required', 'string', 'max:20'],
            'email'    => ['nullable', 'email', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        // Creating an account requires the PIN, same as on the web panel.
        [$pinOk, $pinError] = $this->pins->verify($user, (string) $request->input('mypin'));

        if (! $pinOk) {
            return $this->fail((string) $pinError);
        }

        $reseller = $this->resellers->create($user, $validator->validated() + [
            'custype' => $childLevel->value, 'webAccess' => 1, 'appsAccess' => 1,
        ]);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'message' => 'Account created.',
            'id'      => (int) $reseller->id,
        ]);
    }

    /** POST /apiapp/payment - move balance to or from a sub-account. */
    public function transfer(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->settings->security()->balanceTransferEnabled()) {
            return $this->fail('Balance transfers are disabled.');
        }

        $validator = validator($request->all(), [
            'userid' => ['required', 'integer', 'exists:reseller,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'type'   => ['required', 'in:add,return'],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        $target = Reseller::findOrFail((int) $request->input('userid'));

        if (! $this->permissions->canManage($user, $target)) {
            return $this->fail('That account is not in your network.');
        }

        [$pinOk, $pinError] = $this->pins->verify($user, (string) $request->input('pincode'));

        if (! $pinOk) {
            return $this->fail((string) $pinError);
        }

        $amount = (float) $request->input('amount');
        $action = (string) $request->input('type');
        [$from, $to] = $action === 'add' ? [$user, $target] : [$target, $user];

        $description = sprintf(
            '%s %s via app',
            $action === 'add' ? 'Transfer to' : 'Collected from',
            $target->username,
        );

        try {
            DB::transaction(function () use ($from, $to, $amount, $description, $user, $target, $action): void {
                $this->wallet->transfer($from, $to, WalletType::Main, $amount, $description);

                BalanceTransfer::create([
                    'userid'        => (int) $target->id,
                    'p_id'          => (int) $user->id,
                    'trxid'         => strtoupper(bin2hex(random_bytes(5))),
                    'desc'          => $description,
                    'remark'        => 'app',
                    'sender'        => (string) $user->username,
                    'debit'         => $action === 'add' ? 0 : $amount,
                    'credit'        => $action === 'add' ? $amount : 0,
                    'account'       => (float) $target->fresh()->balance,
                    'type'          => WalletType::Main->value,
                    'transfer_type' => $action,
                    'idate'         => now()->format('Y-m-d'),
                    'date'          => now()->format('Y-m-d H:i:s'),
                ]);
            });
        } catch (WalletException $e) {
            return $this->fail($e->getMessage());
        }

        $this->audit->record($user, 'app.balance_'.$action, $description, [
            'reseller_id' => (int) $target->id, 'amount' => $amount,
        ]);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'message' => 'Balance updated.',
            'balance' => number_format((float) $user->fresh()->balance, 2, '.', ''),
        ]);
    }

    /** GET /apiapp/payinfo - where to send a deposit. */
    public function depositInfo(Request $request): JsonResponse
    {
        return response()->json([
            'success' => 1,
            'status'  => 1,
            'gateway' => ManualGateway::query()
                ->where('status', 1)
                ->orderBy('id')
                ->get(['id', 'name', 'number', 'note', 'image'])
                ->map(fn (ManualGateway $g) => [
                    'id'     => (int) $g->id,
                    'name'   => (string) $g->name,
                    'number' => (string) $g->number,
                    'note'   => (string) $g->note,
                ])->values(),
        ]);
    }

    /** POST /apiapp/makepay - claim a deposit for verification. */
    public function claimDeposit(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validator = validator($request->all(), [
            'gateway' => ['required', 'string', 'max:120'],
            'trxid'   => ['required', 'string', 'min:4', 'max:60'],
            'amount'  => ['required', 'numeric', 'min:1', 'max:1000000'],
            'sender'  => ['required', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        $trxId = (string) $request->input('trxid');

        // One claim per transaction id, so the same deposit cannot be
        // submitted twice and approved twice.
        if (PaymentRequest::query()->where('trx_id', $trxId)->exists()) {
            return $this->fail('That transaction ID has already been submitted.');
        }

        PaymentRequest::create([
            'userid'      => (string) $user->id,
            'gatway'      => (string) $request->input('gateway'),
            'trx_id'      => $trxId,
            'amount'      => (string) $request->input('amount'),
            'fee'         => '0',
            'tota_amount' => (string) $request->input('amount'),
            'sender'      => (string) $request->input('sender'),
            'sender_name' => $user->displayName(),
            'ip'          => (string) $request->ip(),
            'status'      => 0,
            'idate'       => now()->format('Y-m-d'),
            'time'        => now()->format('H:i:s'),
        ]);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'message' => 'Deposit submitted. Your balance will be credited once verified.',
        ]);
    }

    /** GET /apiapp/paymentlist */
    public function payments(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'payment' => BalanceTransfer::query()
                ->where('p_id', $user->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (BalanceTransfer $row) => [
                    'id'     => (int) $row->id,
                    'trxid'  => (string) $row->trxid,
                    'desc'   => (string) $row->desc,
                    'debit'  => number_format((float) $row->debit, 2, '.', ''),
                    'credit' => number_format((float) $row->credit, 2, '.', ''),
                    'date'   => (string) $row->date,
                ])->values(),
        ]);
    }

    /** GET /apiapp/receivelist */
    public function receipts(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'receive' => BalanceTransfer::query()
                ->where('userid', $user->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (BalanceTransfer $row) => [
                    'id'     => (int) $row->id,
                    'trxid'  => (string) $row->trxid,
                    'desc'   => (string) $row->desc,
                    'debit'  => number_format((float) $row->debit, 2, '.', ''),
                    'credit' => number_format((float) $row->credit, 2, '.', ''),
                    'date'   => (string) $row->date,
                ])->values(),
        ]);
    }

    /** GET /apiapp/reports */
    public function reports(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $summary = $this->metrics->resellerSummary($user);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'report'  => [
                'today_count'   => $summary['today']['count'],
                'today_volume'  => number_format($summary['today']['volume'], 2, '.', ''),
                'month_count'   => $summary['month']['count'],
                'month_volume'  => number_format($summary['month']['volume'], 2, '.', ''),
                'month_savings' => number_format($summary['month']['savings'], 2, '.', ''),
                'success'       => $summary['statuses']['success'],
                'pending'       => $summary['statuses']['waiting'] + $summary['statuses']['pending'],
                'cancelled'     => $summary['statuses']['cancelled'],
                'downline'      => $summary['downline'],
            ],
        ]);
    }

    /** POST /apiapp/resellerEdit - update own profile. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validator = validator($request->all(), [
            'name'   => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email'  => ['nullable', 'email', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        Reseller::query()->where('id', $user->id)->update($validator->validated());

        return response()->json(['success' => 1, 'status' => 1, 'message' => 'Profile updated.']);
    }

    /** POST /apiapp/password */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $current = (string) $request->input('oldpassword');
        $new = (string) $request->input('newpassword');

        if (! $this->hasher->check($current, (string) $user->password)) {
            return $this->fail('Your current password is incorrect.');
        }

        if (strlen($new) < 8) {
            return $this->fail('The new password must be at least 8 characters.');
        }

        DB::table('reseller')->where('id', $user->id)->update([
            'password'      => $this->hasher->make($new),
            'chnage_pass_d' => now()->format('Y-m-d'),
            'chnage_pass_t' => now()->format('H:i:s'),
        ]);

        $this->audit->record($user, 'app.password_changed', 'Changed password from app');

        return response()->json(['success' => 1, 'status' => 1, 'message' => 'Password changed.']);
    }

    /** POST /apiapp/pin */
    public function changePin(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $minimum = $this->settings->security()->minimumPinLength();

        if (! $this->hasher->check((string) $request->input('password'), (string) $user->password)) {
            return $this->fail('Your password is incorrect.');
        }

        $new = (string) $request->input('newpin');

        if ($error = $this->pins->validateStrength($new, $minimum)) {
            return $this->fail($error);
        }

        $this->pins->store($user, $new);

        $this->audit->record($user, 'app.pin_changed', 'Changed PIN from app');

        return response()->json(['success' => 1, 'status' => 1, 'message' => 'PIN changed.']);
    }

    /** GET /apiapp/tricket_main */
    public function tickets(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'ticket'  => Ticket::query()
                ->where('id_user', $user->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'subject', 'c_date', 'status'])
                ->values(),
        ]);
    }

    /** GET /apiapp/trickets */
    public function ticketMessages(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ticketId = (int) $request->input('tid');

        $ticket = Ticket::query()
            ->where('id', $ticketId)
            ->where('id_user', $user->id)
            ->first();

        if ($ticket === null) {
            return $this->fail('Ticket not found.');
        }

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'message' => TicketMessage::query()
                ->where('tricket_id', $ticket->id)
                ->orderBy('id')
                ->get(['id', 'id_user', 'msg', 'date', 'time'])
                ->values(),
        ]);
    }

    /** POST /apiapp/tricket_new */
    public function createTicket(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validator = validator($request->all(), [
            'subject' => ['required', 'string', 'max:200'],
            'msg'     => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first());
        }

        $ticket = DB::transaction(function () use ($user, $request): Ticket {
            $ticket = Ticket::create([
                'id_user' => (int) $user->id,
                'subject' => (string) $request->input('subject'),
                'c_date'  => (string) now()->getTimestamp(),
                'status'  => 0,
            ]);

            TicketMessage::create([
                'id_user'    => (int) $user->id,
                'msg'        => (string) $request->input('msg'),
                'ip'         => (string) $request->ip(),
                'date'       => now()->format('Y-m-d'),
                'time'       => now()->format('H:i:s'),
                'ctime'      => (string) now()->getTimestamp(),
                'tricket_id' => (int) $ticket->id,
            ]);

            return $ticket;
        });

        return response()->json([
            'success' => 1,
            'status'  => 1,
            'message' => 'Ticket opened.',
            'id'      => (int) $ticket->id,
        ]);
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['success' => 0, 'status' => 2, 'message' => $message]);
    }
}
