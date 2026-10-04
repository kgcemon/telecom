<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\ActivityLog;
use App\Models\Reseller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records privileged and security-relevant actions to `activity_log`.
 *
 * Writing an audit line must never break the action being audited, so every
 * failure here is swallowed and reported to the application log instead. The
 * legacy code inlined these inserts into business logic, which meant a
 * malformed log row could abort a balance transfer.
 */
class AuditLogger
{
    /**
     * @param  Reseller|int|null  $actor  Who performed the action.
     * @param  string             $event  Dotted event name, e.g. `reseller.created`.
     * @param  array              $context Extra detail, stored as JSON.
     */
    public function record(
        Reseller|int|null $actor,
        string $event,
        string $message,
        array $context = [],
    ): void {
        try {
            $actorId = $actor instanceof Reseller ? (int) $actor->id : $actor;
            $now = Carbon::now();

            ActivityLog::create([
                'userid'  => $actorId !== null ? (string) $actorId : null,
                'ctype'   => Str::limit($event, 120, ''),
                'msg'     => Str::limit($message, 250, ''),
                'secret'  => $this->encodeContext($context),
                'session' => Str::limit((string) $this->sessionId(), 120, ''),
                'ip'      => Str::limit((string) $this->ip(), 60, ''),
                'browser' => Str::limit((string) $this->userAgent(), 250, ''),
                'os'      => Str::limit((string) $this->platform(), 120, ''),
                'time'    => $now->format('H:i:s'),
                'date'    => $now->format('Y-m-d'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Convenience wrapper for authentication events. */
    public function recordAuth(Reseller|int|null $actor, string $event, string $message, array $context = []): void
    {
        $this->record($actor, 'auth.'.$event, $message, $context);
    }

    /**
     * Context is stored as JSON, with anything that looks like a secret
     * removed. An audit trail that contains PINs or API keys turns a read-only
     * log leak into a credential leak.
     */
    private function encodeContext(array $context): string
    {
        $redactKeys = ['pin', 'pincode', 'password', 'api_key', 'apikey', 'token', 'secret', 'access_pass'];

        array_walk_recursive($context, function (&$value, $key) use ($redactKeys) {
            if (in_array(strtolower((string) $key), $redactKeys, true)) {
                $value = '[redacted]';
            }
        });

        return Str::limit((string) json_encode($context, JSON_UNESCAPED_SLASHES), 980, '');
    }

    private function ip(): ?string
    {
        return $this->inRequest() ? Request::ip() : null;
    }

    private function userAgent(): ?string
    {
        return $this->inRequest() ? Request::userAgent() : 'cli';
    }

    private function sessionId(): ?string
    {
        if (! $this->inRequest()) {
            return null;
        }

        try {
            return Request::hasSession() ? Request::session()->getId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function platform(): ?string
    {
        $agent = (string) $this->userAgent();

        return match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS')  => 'macOS',
            str_contains($agent, 'Linux')   => 'Linux',
            $agent === 'cli'                => 'CLI',
            default                         => 'Unknown',
        };
    }

    private function inRequest(): bool
    {
        return app()->bound('request') && ! app()->runningInConsole();
    }
}
