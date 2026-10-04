<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\ApiProvider;
use App\Models\RechargeRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver for the platform's own `/sendapi/request` protocol, which is what
 * sister installations of this software expose to each other. Used for every
 * `api_set.provider` value without a dedicated driver.
 *
 * Credentials travel in headers (as the legacy code intended) and the body is
 * form-encoded, matching the receiving endpoint. Timeouts are explicit for the
 * same reason as in EcareGateway: an unbounded cURL call holds a worker open
 * indefinitely.
 */
class GenericApiGateway implements ProviderGateway
{
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 30;

    public function name(): string
    {
        return 'generic';
    }

    public function dispatch(ApiProvider $provider, RechargeRequest $request): ProviderResult
    {
        $endpoint = $this->endpoint($provider, $provider->url_peramiter ?: '/sendapi/request');

        $payload = [
            'number'   => $request->phone,
            'service'  => (string) $request->service,
            'amount'   => (string) (int) round((float) $request->balance),
            'type'     => (string) ($request->type ?: '1'),
            'id'       => $request->sid,
            'operator' => (string) $request->pcode,
        ];

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'api-key'  => (string) $provider->api_key,
                    'api-user' => (string) $provider->userid,
                    'Accept'   => 'application/json',
                ])
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

        if (! is_array($decoded) || ! array_key_exists('status', $decoded)) {
            return ProviderResult::unknown(
                message: 'Unreadable provider response',
                raw: $raw,
                endpoint: $endpoint,
            );
        }

        $status = is_string($decoded['status']) ? strtoupper($decoded['status']) : (string) $decoded['status'];
        $trxId = $decoded['trxid'] ?? $decoded['transaction_id'] ?? null;
        $message = $decoded['message'] ?? $decoded['msg'] ?? null;

        return match (true) {
            in_array($status, ['1', 'RECEIVED', 'ACCEPTED', 'PENDING'], true) => ProviderResult::accepted(
                transactionId: $trxId ? (string) $trxId : null,
                message: $message ? (string) $message : null,
                raw: $raw,
                endpoint: $endpoint,
            ),
            in_array($status, ['3', 'SUCCESS', 'COMPLETED'], true) => ProviderResult::completed(
                transactionId: $trxId ? (string) $trxId : null,
                message: $message ? (string) $message : null,
                raw: $raw,
                endpoint: $endpoint,
            ),
            in_array($status, ['0', '2', 'FAILED', 'REJECTED', 'INVALID', 'ERROR'], true) => ProviderResult::rejected(
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
            $response = Http::withHeaders([
                'api-key'  => (string) $provider->api_key,
                'api-user' => (string) $provider->userid,
                'Accept'   => 'application/json',
            ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($this->endpoint($provider, (string) $provider->bal_url));
        } catch (Throwable) {
            return null;
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        $field = $provider->bal_response ?: 'balance';

        foreach ([$field, 'balance', 'amount'] as $key) {
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
            $response = Http::withHeaders([
                'api-key'  => (string) $provider->api_key,
                'api-user' => (string) $provider->userid,
                'Accept'   => 'application/json',
            ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($this->endpoint($provider, (string) $provider->resurl), ['id' => $request->sid]);
        } catch (Throwable $e) {
            return ProviderResult::unknown(message: $e->getMessage());
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('status', $decoded)) {
            return ProviderResult::unknown(raw: $response->body());
        }

        $status = is_string($decoded['status']) ? strtoupper($decoded['status']) : (string) $decoded['status'];

        return match (true) {
            in_array($status, ['3', 'SUCCESS', 'COMPLETED'], true) => ProviderResult::completed(
                transactionId: isset($decoded['trxid']) ? (string) $decoded['trxid'] : null,
                raw: $response->body(),
            ),
            in_array($status, ['0', '2', 'FAILED', 'REJECTED'], true) => ProviderResult::rejected(
                message: (string) ($decoded['message'] ?? 'Rejected by provider'),
                raw: $response->body(),
            ),
            default => ProviderResult::unknown(raw: $response->body()),
        };
    }

    private function endpoint(ApiProvider $provider, string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $host = trim((string) $provider->url);

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = (config('telecom.providers.force_https') ? 'https://' : 'http://').$host;
        }

        if (! blank($provider->port)) {
            $parsed = parse_url($host);

            if (! isset($parsed['port'])) {
                $host = rtrim($host, '/').':'.trim((string) $provider->port);
            }
        }

        return rtrim($host, '/').'/'.ltrim($path, '/');
    }
}
