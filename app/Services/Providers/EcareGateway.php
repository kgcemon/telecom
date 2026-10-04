<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\ApiProvider;
use App\Models\RechargeRequest;
use App\Services\Recharge\OperatorResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver for the Ecare / ECT provider (`api_set.provider = 11`).
 *
 * Two problems in the legacy integration are fixed here.
 *
 * First, credentials were sent as GET query parameters, which puts the access
 * password into the provider's access logs, any proxy in between, and this
 * server's own outbound request logs. They are sent as a POST body instead.
 *
 * Second, the call had no timeout at all. cURL then blocks for as long as the
 * remote end keeps the socket open, holding a PHP worker and the database
 * transaction with it; a slow provider was enough to exhaust the pool and take
 * the whole panel down. Connect and overall timeouts are set explicitly, and a
 * timeout is reported as indeterminate rather than as a failure, so a request
 * the provider may already have accepted is never refunded on a guess.
 */
class EcareGateway implements ProviderGateway
{
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(private readonly OperatorResolver $operators) {}

    public function name(): string
    {
        return 'ecare';
    }

    public function dispatch(ApiProvider $provider, RechargeRequest $request): ProviderResult
    {
        $endpoint = $this->endpoint($provider, '/myportal/api/rechargeapi/recharge_api_thirdparty.php');

        $payload = [
            'number'      => $request->phone,
            'service'     => 'MRC',
            'amount'      => (string) (int) round((float) $request->balance),
            'number_type' => (string) ($request->type ?: '1'),
            'refid'       => $request->sid,
            'access_id'   => $provider->userid,
            'access_pass' => $provider->api_key,
            'operator'    => $this->operatorDigit($request),
        ];

        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            return ProviderResult::unknown(
                message: 'Provider unreachable: '.$e->getMessage(),
                endpoint: $endpoint,
            );
        }

        $raw = $response->body();

        if ($response->serverError()) {
            return ProviderResult::unknown(
                message: 'Provider returned HTTP '.$response->status(),
                raw: $raw,
                endpoint: $endpoint,
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            // An unparseable body is not proof of rejection.
            return ProviderResult::unknown(
                message: 'Unreadable provider response',
                raw: $raw,
                endpoint: $endpoint,
            );
        }

        $status = strtoupper((string) ($decoded['RECHARGE_STATUS'] ?? ''));
        $trxId = $decoded['TRANSACTION_ID'] ?? $decoded['TRXID'] ?? null;
        $message = $decoded['MESSAGE'] ?? $decoded['REASON'] ?? null;

        return match ($status) {
            'RECEIVED', 'ACCEPTED', 'PENDING', 'PROCESSING' => ProviderResult::accepted(
                transactionId: $trxId ? (string) $trxId : null,
                message: $message ? (string) $message : null,
                raw: $raw,
                endpoint: $endpoint,
            ),
            'SUCCESS', 'COMPLETED' => ProviderResult::completed(
                transactionId: $trxId ? (string) $trxId : null,
                message: $message ? (string) $message : null,
                raw: $raw,
                endpoint: $endpoint,
            ),
            'FAILED', 'REJECTED', 'INVALID', 'ERROR' => ProviderResult::rejected(
                message: $message ? (string) $message : 'Rejected by provider',
                raw: $raw,
                endpoint: $endpoint,
            ),
            default => ProviderResult::unknown(
                message: 'Unrecognised provider status: '.($status ?: 'empty'),
                raw: $raw,
                endpoint: $endpoint,
            ),
        };
    }

    public function balance(ApiProvider $provider): ?float
    {
        if (blank($provider->bal_url)) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($this->endpoint($provider, (string) $provider->bal_url), [
                    'access_id'   => $provider->userid,
                    'access_pass' => $provider->api_key,
                ]);
        } catch (Throwable) {
            return null;
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['BALANCE', 'balance', 'AVAILABLE_BALANCE'] as $key) {
            if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                return (float) $decoded[$key];
            }
        }

        return null;
    }

    public function status(ApiProvider $provider, RechargeRequest $request): ?ProviderResult
    {
        if (blank($provider->resurl)) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($this->endpoint($provider, (string) $provider->resurl), [
                    'refid'       => $request->sid,
                    'access_id'   => $provider->userid,
                    'access_pass' => $provider->api_key,
                ]);
        } catch (Throwable $e) {
            return ProviderResult::unknown(message: $e->getMessage());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return ProviderResult::unknown(raw: $response->body());
        }

        $status = strtoupper((string) ($decoded['RECHARGE_STATUS'] ?? ''));

        return match ($status) {
            'SUCCESS', 'COMPLETED' => ProviderResult::completed(
                transactionId: isset($decoded['TRANSACTION_ID']) ? (string) $decoded['TRANSACTION_ID'] : null,
                raw: $response->body(),
            ),
            'FAILED', 'REJECTED', 'INVALID' => ProviderResult::rejected(
                message: (string) ($decoded['MESSAGE'] ?? 'Rejected by provider'),
                raw: $response->body(),
            ),
            default => ProviderResult::unknown(raw: $response->body()),
        };
    }

    /**
     * Build an absolute URL from the stored host.
     *
     * `api_set.url` holds a bare host or IP in existing rows, so a scheme is
     * added when missing. HTTPS is preferred and can be forced per provider.
     */
    private function endpoint(ApiProvider $provider, string $path): string
    {
        $host = trim((string) $provider->url);

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = (config('telecom.providers.force_https') ? 'https://' : 'http://').$host;
        }

        if (! blank($provider->port) && ! str_contains(parse_url($host, PHP_URL_HOST) ?? '', ':')) {
            $host = rtrim($host, '/').':'.trim((string) $provider->port);
        }

        return str_starts_with($path, 'http')
            ? $path
            : rtrim($host, '/').'/'.ltrim($path, '/');
    }

    /**
     * Ecare identifies the operator by a single digit taken from the number
     * prefix, with Skitto as a fixed special case.
     */
    private function operatorDigit(RechargeRequest $request): string
    {
        if (strcasecmp((string) $request->pcode, 'SK') === 0) {
            return '3';
        }

        $prefix = $this->operators->prefixOf((string) $request->phone);

        return $prefix !== '' ? substr($prefix, -1) : '0';
    }
}
