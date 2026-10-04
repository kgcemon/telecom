<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\ServiceType;
use App\Models\Notice;
use App\Models\Reseller;
use App\Enums\WalletType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Response shapes the installed Android client expects.
 *
 * These are not designs anyone would choose; they are the contract an APK
 * already in users' hands reads, and getting a single key wrong makes a screen
 * crash rather than degrade. Three quirks in particular are deliberate:
 *
 *  - list endpoints wrap their payload in `{"bmtelbd": [...]}`
 *  - `message` on the balance and role endpoints carries the *balance*, not a
 *    message
 *  - the client uses `getString()`, which throws on a missing key, so every
 *    field it reads must always be present even when empty
 *
 * Keep this file as the single place those quirks live, so the controllers
 * stay readable and a future client can be served a sane shape alongside.
 */
trait SpeaksLegacyApp
{
    /** A list response, in the envelope the client unpacks. */
    protected function listResponse(array $rows): JsonResponse
    {
        return response()->json(['bmtelbd' => array_values($rows)]);
    }

    /** A plain success envelope. */
    protected function ok(array $extra = []): JsonResponse
    {
        return response()->json(['success' => 1, 'status' => 1] + $extra);
    }

    /**
     * A failure envelope.
     *
     * `status = 2` is what the client treats as "signed out" and what sends it
     * back to the login screen, so it is the default.
     */
    protected function fail(string $message, int $status = 2): JsonResponse
    {
        return response()->json(['success' => 0, 'status' => $status, 'message' => $message]);
    }

    /** A failure inside a list envelope, which some screens expect instead. */
    protected function failList(string $message): JsonResponse
    {
        return response()->json([
            'bmtelbd' => ['success' => 0, 'status' => 2, 'message' => $message],
        ]);
    }

    protected function currentReseller(Request $request): ?Reseller
    {
        $user = $request->attributes->get('current_reseller');

        return $user instanceof Reseller ? $user : null;
    }

    /**
     * Balance as the client renders it: a bare two-decimal string.
     *
     * The client puts this straight into a TextView, so it must not carry a
     * currency symbol or thousands separators.
     */
    protected function balanceFor(Reseller $user, ?string $type = null): string
    {
        $wallet = match ($type) {
            'bank', '2'  => WalletType::Bank,
            'drive', '3' => WalletType::Drive,
            default      => WalletType::Main,
        };

        return number_format((float) $user->balanceFor($wallet), 2, '.', '');
    }

    /** The newest active notice, as plain text. */
    protected function currentNotice(): string
    {
        $notice = Notice::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->value('notice');

        return trim(strip_tags((string) $notice));
    }

    /** Absolute URL of a service icon, falling back to the shared default. */
    protected function serviceIcon(int|string $serviceId): string
    {
        $file = public_path("assets/img/{$serviceId}.png");

        return is_file($file)
            ? url("assets/img/{$serviceId}.png")
            : url('assets/img/default.png');
    }

    /**
     * Numeric level string the client uses to decide what a reseller may do.
     * Sub-admins are the one case that stays a word.
     */
    protected function levelCode(Reseller $user): string
    {
        return match ((string) $user->custype) {
            'admin', 'subadmin' => 'subadmin',
            'reseller5'         => '5',
            'reseller4'         => '4',
            'reseller3'         => '3',
            'reseller2'         => '2',
            'reseller1'         => '1',
            default             => '1',
        };
    }

    /**
     * Which screen the client opens for a service tile.
     *
     * Taken from the legacy mapping verbatim; an unknown value here leaves the
     * tile inert, so the default is the generic wallet screen.
     */
    protected function activityFor(ServiceType $service): string
    {
        return match ($service) {
            ServiceType::Flexiload    => 'recharge',
            ServiceType::InternetBank => 'banktransfer',
            ServiceType::Sms          => 'sms',
            ServiceType::InternetPack => 'internet',
            ServiceType::BillPay,
            ServiceType::BillPay2     => 'bank',
            default                   => 'mobile',
        };
    }

    /**
     * Services the client cannot render a tile for. Excluded by the legacy
     * endpoint too; listing them produces a tile that opens nothing.
     */
    protected function hiddenFromAppTiles(): array
    {
        return [
            ServiceType::BulkFlexi->value,
            ServiceType::GlobalFlexi->value,
            ServiceType::MatirBank->value,
            ServiceType::TeleCash->value,
            ServiceType::BillPay2->value,
        ];
    }
}
