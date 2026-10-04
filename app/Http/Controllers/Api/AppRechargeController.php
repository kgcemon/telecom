<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\Concerns\SpeaksLegacyApp;
use App\Models\BankTransfer;
use App\Models\BillPayment;
use App\Models\RechargeRequest;
use App\Services\Recharge\Dto\RechargeInput;
use App\Services\Recharge\RechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recharge endpoints for the Android client.
 *
 * Requests go through the same RechargeService as the web panel, so limits,
 * pricing, PIN enforcement and the balance lock are identical. The legacy app
 * path had its own copy of the flow, which is how it ended up with a weaker
 * set of rules and the `pincode=api` bypass.
 */
class AppRechargeController
{
    use SpeaksLegacyApp;

    public function __construct(private readonly RechargeService $recharge) {}

    /** POST /apiapp/NewRequest */
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentReseller($request);

        if ($user === null) {
            return $this->fail('Not Match.');
        }

        $service = ServiceType::tryFrom((int) $request->input('service'));

        if ($service === null) {
            return $this->fail('That service is not available.');
        }

        $number = trim((string) $request->input('number'));

        // Postpaid bill pay is sent without its leading "10"; the legacy
        // handler prefixed it back on, so existing builds depend on that.
        if ($service === ServiceType::BillPay2) {
            $number = '10'.$number;
        }

        // Some screens split the customer name across two fields.
        $name = trim(trim((string) $request->input('unumber')).' '.trim((string) $request->input('name')));

        $outcome = $this->recharge->place(new RechargeInput(
            reseller: $user,
            service: $service,
            number: $number,
            amount: (float) $request->input('amount'),
            numberType: $service === ServiceType::BillPay2 ? '1' : (string) ($request->input('type') ?: '1'),
            pin: (string) $request->input('pincode'),
            // The client sends either a short code or a full operator name;
            // OperatorResolver normalises both.
            operatorCode: ($optn = trim((string) $request->input('optn'))) !== '' ? $optn : null,
            packageId: $request->filled('orderid') ? (int) $request->input('orderid') : null,
            customerName: $name !== '' ? $name : null,
            nationalId: $request->input('nidcode'),
            senderNumber: $request->input('senderno'),
            remark: 'apps',
            channel: 'app',
            ipAddress: $request->ip(),
        ));

        return $outcome->successful
            ? $this->ok(['message' => $outcome->message])
            : $this->fail($outcome->message);
    }

    /**
     * POST /apiapp/RechargeHistory
     *
     * Every field the client reads must be present even when empty, because it
     * uses getString() throughout and that throws on a missing key.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->currentReseller($request);

        if ($user === null) {
            return $this->failList('Not Match.');
        }

        $limit = min(200, max(10, (int) $request->input('limit', 60)));

        $rows = RechargeRequest::query()
            ->where('userid', $user->id)
            ->when($request->filled('service'), fn ($q) => $q->where('service', (int) $request->input('service')))
            ->when($request->filled('from'), fn ($q) => $q->where('idate', '>=', (string) $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('idate', '<=', (string) $request->input('to')))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (RechargeRequest $row) => [
                'number'      => (string) $row->phone,
                'balance'     => number_format((float) $row->balance, 2, '.', ''),
                'cost'        => number_format((float) $row->cost, 2, '.', ''),
                'prebalance'  => number_format((float) $row->prebalance, 2, '.', ''),
                'serviceName' => $row->serviceLabel(),
                'type'        => (string) ($row->type ?: '1'),
                'status'      => $this->statusWord($row->status),
                'trxID'       => (string) $row->trxid,
                'time'        => (string) ($row->submitted_date?->format('d M Y h:i A') ?? ''),
                'sender'      => (string) $row->sender_no,
                'ut'          => (string) $row->actual_send_time,
                'img'         => $this->serviceIcon((int) $row->service),
                'service'     => (string) $row->service,
                'pcode'       => (string) $row->pcode,
                'debit'       => number_format((float) $row->cost, 2, '.', ''),
                'credit'      => '0.00',
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /**
     * POST /apiapp/Otherhistory
     *
     * Bank transfers and bill payments live in their own tables, merged here
     * into the one list the client renders.
     */
    public function otherHistory(Request $request): JsonResponse
    {
        $user = $this->currentReseller($request);

        if ($user === null) {
            return $this->failList('Not Match.');
        }

        $limit = min(200, max(10, (int) $request->input('limit', 60)));

        $banks = BankTransfer::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BankTransfer $row) => $this->otherRow(
                number: (string) $row->account,
                title: (string) $row->bank_name,
                amount: (float) $row->balance,
                cost: (float) $row->cost,
                status: (int) $row->status,
                date: (string) $row->date,
                serviceId: ServiceType::InternetBank->value,
                holder: (string) $row->holdername,
                trxId: (string) $row->trxid,
            ));

        $bills = BillPayment::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BillPayment $row) => $this->otherRow(
                number: (string) $row->account,
                title: (string) $row->provider_name,
                amount: (float) $row->balance,
                cost: (float) $row->cost,
                status: (int) $row->status,
                date: (string) $row->date,
                serviceId: ServiceType::BillPay->value,
                holder: (string) $row->holdername,
                trxId: (string) $row->trxid,
            ));

        return $this->listResponse(
            $banks->concat($bills)->sortByDesc('time')->take($limit)->values()->all()
        );
    }

    private function otherRow(
        string $number,
        string $title,
        float $amount,
        float $cost,
        int $status,
        string $date,
        int $serviceId,
        string $holder,
        string $trxId,
    ): array {
        return [
            'number'      => $number,
            'balance'     => number_format($amount, 2, '.', ''),
            'cost'        => number_format($cost, 2, '.', ''),
            'prebalance'  => '0.00',
            'serviceName' => $title,
            'type'        => '1',
            'status'      => $this->statusWord(TransactionStatus::tryFrom($status) ?? TransactionStatus::Pending),
            'trxID'       => $trxId,
            'time'        => $date,
            'sender'      => $holder,
            'ut'          => $date,
            'img'         => $this->serviceIcon($serviceId),
            'service'     => (string) $serviceId,
            'pcode'       => '',
            'debit'       => number_format($cost, 2, '.', ''),
            'credit'      => '0.00',
        ];
    }

    /**
     * The client renders this string directly and colours the row from it, so
     * the wording has to match what it has always shown.
     */
    private function statusWord(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::Success    => 'Success',
            TransactionStatus::Cancelled  => 'Cancel',
            TransactionStatus::Waiting    => 'Waiting',
            TransactionStatus::Processing => 'Processing',
            TransactionStatus::Partial    => 'Partial',
            TransactionStatus::Pending    => 'Pending',
        };
    }
}
