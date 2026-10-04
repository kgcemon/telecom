<?php

declare(strict_types=1);

namespace App\Services\Recharge;

use App\Models\BlockRule;
use App\Models\RechargeRequest;
use App\Models\Service;
use App\Services\Recharge\Dto\RechargeInput;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;

/**
 * All pre-flight checks for a request, in one place.
 *
 * The legacy code ran these as a staircase of nested `if` statements roughly
 * fifteen levels deep, with the failure message assigned in the matching
 * `else` branches far below. Several of those `else` branches were missing, so
 * a request that failed the check fell through to the end of the function and
 * was reported as a success. Each rule here returns an explicit reason, and
 * the caller treats anything non-null as a refusal.
 *
 * Returns null when the request is acceptable, or `[code, message]` when it is
 * not.
 */
class RechargeValidator
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OperatorResolver $operators,
    ) {}

    /**
     * @return array{0: string, 1: string}|null
     */
    public function validate(RechargeInput $input, Service $service): ?array
    {
        foreach ([
            'checkAccount',
            'checkAmountSanity',
            'checkHardLimits',
            'checkServiceEnvelope',
            'checkNumber',
            'checkOperator',
            'checkRequiredFields',
            'checkBlockRules',
            'checkDuplicate',
            'checkDailyLimit',
        ] as $rule) {
            if ($failure = $this->{$rule}($input, $service)) {
                return $failure;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- rules

    private function checkAccount(RechargeInput $input, Service $service): ?array
    {
        $reseller = $input->reseller;

        if (! $reseller->isActive()) {
            return ['account_inactive', 'Your account is not active. Please contact support.'];
        }

        if ((int) $reseller->recharge !== 1) {
            return ['recharge_disabled', 'Recharge is disabled on your account.'];
        }

        return null;
    }

    private function checkAmountSanity(RechargeInput $input, Service $service): ?array
    {
        if (! is_finite($input->amount) || $input->amount <= 0) {
            return ['amount_invalid', 'Enter a valid amount.'];
        }

        // Fractional top-ups are not deliverable by any operator and were a
        // common way to probe rounding behaviour in the old pricing code.
        if (round($input->amount, 2) !== round($input->amount, 0)
            && ! in_array($input->service->value, [8, 32, 524288], true)) {
            return ['amount_not_whole', 'Amount must be a whole number.'];
        }

        return null;
    }

    private function checkHardLimits(RechargeInput $input, Service $service): ?array
    {
        $max = (float) config('telecom.limits.max_single_request');

        if ($max > 0 && $input->amount > $max) {
            return ['amount_above_ceiling', 'Amount exceeds the maximum allowed per request.'];
        }

        return null;
    }

    private function checkServiceEnvelope(RechargeInput $input, Service $service): ?array
    {
        if ((int) $service->status !== 1) {
            return ['service_disabled', $service->title.' is currently unavailable.'];
        }

        $min = (float) $service->min_amount;
        $max = (float) $service->max_amount;

        if ($min > 0 && $input->amount < $min) {
            return ['amount_below_min', sprintf('Minimum amount for %s is %s.', $service->title, number_format($min, 0))];
        }

        if ($max > 0 && $input->amount > $max) {
            return ['amount_above_max', sprintf('Maximum amount for %s is %s.', $service->title, number_format($max, 0))];
        }

        return null;
    }

    private function checkNumber(RechargeInput $input, Service $service): ?array
    {
        $number = $this->operators->normaliseNumber($input->number);

        if ($number === '') {
            return ['number_invalid', 'Enter a valid number.'];
        }

        $length = strlen($number);
        $min = (int) $service->min_length;
        $max = (int) $service->max_length;

        if ($min > 0 && $length < $min) {
            return ['number_too_short', 'The number is too short for this service.'];
        }

        if ($max > 0 && $length > $max) {
            return ['number_too_long', 'The number is too long for this service.'];
        }

        return null;
    }

    private function checkOperator(RechargeInput $input, Service $service): ?array
    {
        if (! $input->service->resolvesOperatorByPrefix()) {
            return null;
        }

        $prefix = $this->operators->prefixOf($input->number);
        $code = $this->operators->resolve($input->number, $input->operatorCode);

        if ($code === null) {
            return ['operator_unknown', 'This number does not belong to a supported operator.'];
        }

        if (! $this->operators->isPrefixEnabled($prefix)) {
            return ['operator_disabled', 'This operator is switched off at the moment.'];
        }

        $expected = $this->operators->expectedLength($prefix);
        $actual = strlen($this->operators->normaliseNumber($input->number));

        if ($expected !== null && $actual !== $expected) {
            return ['number_length_mismatch', 'The number length is not valid for this operator.'];
        }

        return null;
    }

    private function checkRequiredFields(RechargeInput $input, Service $service): ?array
    {
        if ($service->requiresCustomerName() && blank($input->customerName)) {
            return ['name_required', 'Customer name is required for this service.'];
        }

        if ($service->requiresNid() && blank($input->nationalId)) {
            return ['nid_required', 'National ID is required for this service.'];
        }

        if ($service->requiresSenderNumber() && blank($input->senderNumber)) {
            return ['sender_required', 'Sender number is required for this service.'];
        }

        return null;
    }

    private function checkBlockRules(RechargeInput $input, Service $service): ?array
    {
        $code = $this->operators->resolve($input->number, $input->operatorCode);

        $blocked = BlockRule::query()
            ->where('service', $input->service->value)
            ->where('amount', (string) (int) $input->amount)
            ->when($code !== null, fn ($q) => $q->where('pcode', 'like', substr($code, 0, 2).'%'))
            ->where('status', 1)
            ->exists();

        if ($blocked) {
            return ['amount_blocked', 'This amount is not available for this operator right now.'];
        }

        return null;
    }

    /**
     * Reject a repeat of the same request inside a short window.
     *
     * The legacy check compared only "has this number ever had a successful
     * request", which blocked legitimate repeat top-ups months later while
     * doing nothing about a double-tap seconds apart. This looks at the same
     * reseller, number and amount within a configured window instead.
     */
    private function checkDuplicate(RechargeInput $input, Service $service): ?array
    {
        $window = (int) config('telecom.limits.duplicate_window_seconds');
        $interval = $this->settings->security()->requestInterval();
        $seconds = max($window, $interval);

        if ($seconds <= 0) {
            return null;
        }

        $since = Carbon::now()->subSeconds($seconds);
        $number = $this->operators->normaliseNumber($input->number);

        $recent = RechargeRequest::query()
            ->where('userid', $input->reseller->id)
            ->where('service', $input->service->value)
            ->where('phone', $number)
            ->where('balance', (int) $input->amount)
            ->where('submitted_date', '>=', $since)
            ->whereNotIn('status', [\App\Enums\TransactionStatus::Cancelled->value])
            ->exists();

        if ($recent) {
            return [
                'duplicate_request',
                sprintf(
                    'An identical request was placed in the last %d seconds. Please wait before retrying.',
                    $seconds,
                ),
            ];
        }

        return null;
    }

    private function checkDailyLimit(RechargeInput $input, Service $service): ?array
    {
        $configured = $this->settings->security()->dailyResellerLimit();
        $ceiling = (float) config('telecom.limits.max_daily_per_reseller');

        // Honour whichever limit is tighter: the operator setting, or the hard
        // cap that stops a mistaken settings change removing the limit.
        $limit = min(
            $configured > 0 ? $configured : PHP_FLOAT_MAX,
            $ceiling > 0 ? $ceiling : PHP_FLOAT_MAX,
        );

        if ($limit === PHP_FLOAT_MAX) {
            return null;
        }

        $spentToday = (float) RechargeRequest::query()
            ->where('userid', $input->reseller->id)
            ->where('idate', Carbon::now()->format('Y-m-d'))
            ->where('status', '!=', \App\Enums\TransactionStatus::Cancelled->value)
            ->sum('balance');

        if (($spentToday + $input->amount) > $limit) {
            return [
                'daily_limit_reached',
                sprintf('Daily limit of %s reached.', number_format($limit, 0)),
            ];
        }

        return null;
    }
}
