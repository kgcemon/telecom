<?php

declare(strict_types=1);

namespace App\Services\Recharge\Dto;

use App\Enums\ServiceType;
use App\Models\Reseller;

/**
 * Everything needed to place one request, gathered in one place.
 *
 * The legacy entry point took sixteen positional parameters, most of them
 * optional strings, so call sites drifted apart - the web form, the mobile API
 * and the machine API each passed them in a slightly different order and one
 * of them omitted the PIN type entirely. Named construction makes that
 * impossible.
 */
final readonly class RechargeInput
{
    public function __construct(
        public Reseller $reseller,
        public ServiceType $service,
        public string $number,
        public float $amount,
        /** Legacy `type`: 1 = prepaid, 2 = postpaid, 3/4 = wallet variants. */
        public string $numberType = '1',
        /** Plaintext PIN, verified then discarded. Null when not required. */
        public ?string $pin = null,
        /** Operator the client declared; validated before use. */
        public ?string $operatorCode = null,
        /** drive_package / net_package id for bundle purchases. */
        public ?int $packageId = null,
        /** Customer name, when the service requires it. */
        public ?string $customerName = null,
        /** National ID, when the service requires it. */
        public ?string $nationalId = null,
        /** Sender number, when the service requires it. */
        public ?string $senderNumber = null,
        /** Free-text note stored on the request. */
        public ?string $remark = null,
        /** Where the request came from: web, app, api, offline. */
        public string $channel = 'web',
        public ?string $ipAddress = null,
        /** Caller-supplied reference, used to make retries idempotent. */
        public ?string $reference = null,
        /** Country code for international top-ups. */
        public ?string $country = null,
    ) {}

    public function withAmount(float $amount): self
    {
        return new self(
            reseller: $this->reseller,
            service: $this->service,
            number: $this->number,
            amount: $amount,
            numberType: $this->numberType,
            pin: $this->pin,
            operatorCode: $this->operatorCode,
            packageId: $this->packageId,
            customerName: $this->customerName,
            nationalId: $this->nationalId,
            senderNumber: $this->senderNumber,
            remark: $this->remark,
            channel: $this->channel,
            ipAddress: $this->ipAddress,
            reference: $this->reference,
            country: $this->country,
        );
    }

    public function withNumber(string $number): self
    {
        return new self(
            reseller: $this->reseller,
            service: $this->service,
            number: $number,
            amount: $this->amount,
            numberType: $this->numberType,
            pin: $this->pin,
            operatorCode: $this->operatorCode,
            packageId: $this->packageId,
            customerName: $this->customerName,
            nationalId: $this->nationalId,
            senderNumber: $this->senderNumber,
            remark: $this->remark,
            channel: $this->channel,
            ipAddress: $this->ipAddress,
            reference: $this->reference,
            country: $this->country,
        );
    }
}
