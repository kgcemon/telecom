<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Jobs\DispatchRechargeRequest;
use App\Models\ProviderResponse;
use App\Models\RechargeRequest;
use App\Models\Service;
use App\Services\Providers\ProviderResult;
use App\Services\Recharge\Dto\PriceQuote;
use App\Services\Recharge\Dto\RechargeInput;
use App\Services\Recharge\Dto\RechargeOutcome;
use App\Services\Security\AuditLogger;
use App\Services\Security\PinService;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\Exceptions\InsufficientBalanceException;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places a recharge request: validate, price, reserve balance, record, dispatch.
 *
 * The ordering here is the whole point of the class, and it differs from the
 * legacy flow in two ways that matter.
 *
 * 1. Balance is checked and debited inside the same transaction that holds a
 *    row lock on the reseller and inserts the request. The legacy code checked
 *    the balance, inserted the row, then deducted in three separate unlocked
 *    statements, so concurrent requests overspent and a failure between steps
 *    left the books inconsistent.
 *
 * 2. The provider call happens *after* that transaction commits, from a queued
 *    job. The legacy code made a cURL call with no timeout while the request
 *    was mid-flight, so a slow provider held a database connection and a PHP
 *    worker for as long as it felt like - enough to take the panel down.
 *
 * The request is therefore durable before anyone talks to a provider, which is
 * also what makes dispatch safely retryable.
 */
class RechargeService
{
    public function __construct(
        private readonly RechargeValidator $validator,
        private readonly PricingService $pricing,
        private readonly RoutingService $routing,
        private readonly OperatorResolver $operators,
        private readonly WalletService $wallet,
        private readonly PinService $pins,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function place(RechargeInput $input): RechargeOutcome
    {
        $service = Service::query()
            ->where('serviceid', $input->service->value)
            ->first();

        if ($service === null) {
            return RechargeOutcome::failed('service_unknown', 'This service is not configured.');
        }

        if ($failure = $this->validator->validate($input, $service)) {
            return RechargeOutcome::failed($failure[0], $failure[1]);
        }

        // PIN requirement comes from service configuration, never from the
        // request body - that is what made the legacy `pincode=api` bypass work.
        if ($service->requiresPin()) {
            [$pinOk, $pinError] = $this->pins->verify($input->reseller, $input->pin);

            if (! $pinOk) {
                $this->audit->record(
                    $input->reseller,
                    'recharge.pin_failed',
                    'Failed PIN on '.$service->title.' request',
                    ['service' => $input->service->value, 'ip' => $input->ipAddress],
                );

                return RechargeOutcome::failed('pin_invalid', (string) $pinError);
            }
        }

        $number = $this->operators->normaliseNumber($input->number);
        $input = $input->withNumber($number);

        $quote = $this->pricing->quote(
            reseller: $input->reseller,
            service: $input->service,
            amount: $input->amount,
            number: $number,
            numberType: $input->numberType,
            declaredOperator: $input->operatorCode,
            packageId: $input->packageId,
        );

        // An unpriced route must never be sold. The legacy code evaluated a
        // missing price as zero and dispatched the top-up for free.
        if (! $quote->resolved) {
            $this->audit->record(
                $input->reseller,
                'recharge.unpriced',
                'Blocked unpriced request',
                [
                    'service'  => $input->service->value,
                    'operator' => $quote->operatorCode,
                    'amount'   => $input->amount,
                ],
            );

            return RechargeOutcome::failed(
                'price_missing',
                'This operator and amount combination is not priced yet. Please contact support.',
                $quote,
            );
        }

        $route = $this->routing->resolve($input->service, $quote->operatorCode);

        $forcedManual = $this->isForcedManual($input);

        $autoDispatch = $this->routing->isAutoDispatchable(
            service: $input->service,
            amount: $input->amount,
            serviceAutoLimit: $service->autoApprovalLimit(),
            manualRouteCeiling: $this->settings->security()->manualRouteAutoCeiling(),
            route: $route,
            forcedManual: $forcedManual,
        );

        $wallet = $this->settings->security()->walletFor(
            $input->service,
            isPackage: $input->packageId !== null,
        );

        try {
            $request = $this->reserveAndRecord($input, $service, $quote, $route, $wallet, $autoDispatch);
        } catch (InsufficientBalanceException $e) {
            return RechargeOutcome::failed(
                'insufficient_balance',
                sprintf(
                    'Insufficient %s. You need %s more.',
                    $wallet->label(),
                    number_format($e->shortfall(), 2),
                ),
                $quote,
            );
        }

        // Only now, with the request committed, hand it to a provider.
        if ($autoDispatch) {
            DispatchRechargeRequest::dispatch($request->id)->afterCommit();
        }

        return RechargeOutcome::accepted(
            $request,
            $quote,
            $autoDispatch
                ? $service->title.' request submitted successfully.'
                : $service->title.' request submitted and is awaiting approval.',
        );
    }

    /**
     * Cancel a request and return the balance, exactly once.
     *
     * Idempotence comes from the conditional update: the row is only marked
     * cancelled if it is not already, and the refund is only issued when that
     * update actually changed a row. Two operators clicking cancel together
     * therefore produce one refund, not two - the legacy code checked and then
     * updated separately and could refund twice.
     */
    public function cancelAndRefund(RechargeRequest $request, ?int $actorId = null, string $reason = ''): bool
    {
        return DB::transaction(function () use ($request, $actorId, $reason) {
            $claimed = RechargeRequest::query()
                ->where('id', $request->id)
                ->where('refund', '!=', 1)
                ->whereIn('status', array_map(
                    fn (TransactionStatus $s) => $s->value,
                    array_filter(TransactionStatus::cases(), fn ($s) => $s->isRefundable()),
                ))
                ->update([
                    'status'      => TransactionStatus::Cancelled->value,
                    'refund'      => 1,
                    'remark'      => Str::limit(trim($request->remark.' | cancelled: '.$reason), 250, ''),
                    'update_time' => Carbon::now()->format('Y-m-d H:i:s'),
                ]);

            if ($claimed !== 1) {
                // Already final or already refunded; nothing further to do.
                return false;
            }

            $fresh = $request->fresh();
            $amount = (float) $fresh->cost;

            if ($amount > 0) {
                $this->wallet->refund(
                    (int) $fresh->userid,
                    $fresh->wallet(),
                    $amount,
                    sprintf('Refund %s to %s (%s)', $fresh->serviceLabel(), $fresh->phone, $fresh->sid),
                    [
                        'service' => (int) $fresh->service,
                        'flex_id' => (string) $fresh->sid,
                        'send_sl' => (int) $fresh->id,
                    ],
                );
            }

            $this->audit->record(
                $actorId,
                'recharge.cancelled',
                sprintf('Cancelled %s (%s) and refunded %s', $fresh->serviceLabel(), $fresh->sid, number_format($amount, 2)),
                ['request_id' => $fresh->id, 'reason' => $reason],
            );

            return true;
        });
    }

    /** Mark a request delivered, from a provider callback or an operator. */
    public function markSuccessful(RechargeRequest $request, ?string $providerTrxId = null, ?string $note = null): bool
    {
        $updated = RechargeRequest::query()
            ->where('id', $request->id)
            ->whereNotIn('status', [
                TransactionStatus::Success->value,
                TransactionStatus::Cancelled->value,
            ])
            ->update(array_filter([
                'status'        => TransactionStatus::Success->value,
                'trxid'         => $providerTrxId,
                'result'        => $request->result,
                'update_time'   => Carbon::now()->format('Y-m-d H:i:s'),
                'actual_send_time' => Carbon::now()->format('Y-m-d H:i:s'),
            ], fn ($v) => $v !== null));

        if ($updated === 1 && $note !== null) {
            ProviderResponse::create([
                'msg'      => 'Success',
                'body'     => Str::limit($note, 480, ''),
                'id_order' => $request->id,
            ]);
        }

        return $updated === 1;
    }

    /** Record the outcome of a provider call against a request. */
    public function applyProviderResult(RechargeRequest $request, ProviderResult $result): void
    {
        ProviderResponse::create([
            'msg'      => $result->accepted ? 'Accepted' : ($result->indeterminate ? 'Unknown' : 'Rejected'),
            'body'     => Str::limit((string) ($result->rawResponse ?? $result->message), 480, ''),
            'id_order' => $request->id,
            'text'     => Str::limit((string) $result->message, 480, ''),
        ]);

        $request->forceFill([
            'apiurl'        => Str::limit((string) $result->endpoint, 250, ''),
            'apiresponse'   => Str::limit((string) ($result->rawResponse ?? $result->message), 250, ''),
            'respons_trxid' => $result->providerTransactionId,
            'update_time'   => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        if ($result->accepted) {
            $request->status = $result->status;
            $request->sent = 1;

            if ($result->providerTransactionId !== null) {
                $request->trxid = $result->providerTransactionId;
            }
        } elseif ($result->isRefundable()) {
            // An explicit rejection is the only case where refunding is safe.
            $request->save();
            $this->cancelAndRefund($request, null, (string) ($result->message ?? 'rejected by provider'));

            return;
        } else {
            // Indeterminate: leave it in flight for the status poller.
            $request->status = TransactionStatus::Processing;
        }

        $request->save();
    }

    // ------------------------------------------------------------ internals

    /**
     * Insert the request and debit the wallet as one atomic unit, with the
     * reseller row locked for the duration.
     */
    private function reserveAndRecord(
        RechargeInput $input,
        Service $service,
        PriceQuote $quote,
        ?object $route,
        WalletType $wallet,
        bool $autoDispatch,
    ): RechargeRequest {
        return $this->wallet->withLock($input->reseller, function (object $locked) use (
            $input, $service, $quote, $route, $wallet, $autoDispatch
        ): RechargeRequest {
            $now = Carbon::now();
            $reference = $input->reference ?: uniqid('', true);
            $payable = $quote->payable();

            $parentCost = $this->pricing->parentCost(
                reseller: $input->reseller,
                service: $input->service,
                amount: $input->amount,
                number: $input->number,
                numberType: $input->numberType,
                declaredOperator: $input->operatorCode,
                packageId: $input->packageId,
            );

            $ancestors = $input->reseller->ancestorIds();

            $request = RechargeRequest::create([
                'sid'              => $reference,
                'route'            => (string) ($route->route ?? ''),
                'route_id'         => (int) ($route->id ?? 0),
                'userid'           => (int) $locked->id,
                'admin_id'         => (string) ($locked->admin_id ?? ''),
                'lft'              => (int) ($locked->lft ?? 0),
                'rgt'              => (int) ($locked->rgt ?? 0),
                'ip'               => Str::limit((string) $input->ipAddress, 120, ''),
                'p_id'             => (int) ($locked->p_id ?? 0),
                'phone'            => $input->number,
                'sender_no'        => (string) $input->senderNumber,
                'name'             => (string) $input->customerName,
                'nid'              => (string) $input->nationalId,
                'type'             => $input->numberType,
                'balance'          => (int) round($input->amount),
                'cost'             => $payable,
                'submitted_date'   => $now,
                'idate'            => $now->format('Y-m-d'),
                'service'          => $input->service->value,
                'send_number'      => (string) ($input->packageId ?? ''),
                'remark'           => Str::limit((string) ($input->remark ?: $input->channel), 240, ''),
                'send_time_stamp'  => $now->format('Y-m-d H:i:s'),
                'parent_cost'      => $parentCost,
                'status'           => $autoDispatch
                                        ? TransactionStatus::Pending->value
                                        : TransactionStatus::Waiting->value,
                'operator'         => $this->operators->slugForCode($quote->operatorCode),
                'pcode'            => (string) $quote->operatorCode,
                'prebalance'       => (float) $locked->{$wallet->column()},
                'local'            => $autoDispatch ? 0 : 5,
                'refund'           => 0,
                'result'           => $wallet->value,
                'tarif_id'         => (int) ($locked->tarif ?: 0),
                'api'              => $input->channel === 'api' ? 1 : 0,
                'level'            => (int) ($ancestors[0] ?? 0),
                'level2'           => (int) ($ancestors[1] ?? 0),
                'level3'           => (int) ($ancestors[2] ?? 0),
                'level4'           => (int) ($ancestors[3] ?? 0),
                'level5'           => (int) ($ancestors[4] ?? 0),
                'commision'        => (string) $quote->commissionAmount(),
                'resend_count'     => 0,
            ]);

            // Debits through WalletService so the ledger line is written in the
            // same transaction and the credit floor is enforced centrally.
            $this->wallet->debit(
                (int) $locked->id,
                $wallet,
                $payable,
                sprintf('%s %s for %s', $service->title, number_format($input->amount, 2), $input->number),
                [
                    'service' => $input->service->value,
                    'flex_id' => $reference,
                    'send_sl' => (int) $request->id,
                    'type'    => 'minus',
                ],
            );

            return $request;
        });
    }

    /**
     * Whether policy forces this request into the review queue regardless of
     * amount. Covers the two legacy `company` flags that were stored in the
     * `zip` and `city` columns.
     */
    private function isForcedManual(RechargeInput $input): bool
    {
        $company = $this->settings->company();

        if ($input->packageId !== null && $company->forcesManualPackageReview()) {
            return true;
        }

        if ($input->service === \App\Enums\ServiceType::InternetPack
            && $company->forcesManualInternetReview()) {
            return true;
        }

        return false;
    }
}
