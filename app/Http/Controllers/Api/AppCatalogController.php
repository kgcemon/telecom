<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ServiceType;
use App\Http\Controllers\Api\Concerns\SpeaksLegacyApp;
use App\Models\BankName;
use App\Models\BillProvider;
use App\Models\Country;
use App\Models\DrivePackage;
use App\Models\InternetOperator;
use App\Models\InternetPackage;
use App\Models\Notice;
use App\Models\Operator;
use App\Models\PhonebookEntry;
use App\Models\Service;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogue endpoints the Android client uses to build its pickers.
 *
 * Only enabled rows are returned, so switching a service or operator off in the
 * panel removes it from the app without a release. Response shapes come from
 * SpeaksLegacyApp and must stay exactly as the installed client reads them.
 */
class AppCatalogController
{
    use SpeaksLegacyApp;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * GET /apiapp/role
     *
     * The home screen. Returns one row per service tile, each carrying the
     * account balance and the current notice, because the client reads those
     * from whichever tile it happens to render first.
     */
    public function services(Request $request): JsonResponse
    {
        $user = $this->currentReseller($request);

        if ($user === null) {
            return $this->failList('Not Match.');
        }

        $balance = $this->balanceFor($user, $request->input('type'));
        $notice = $this->currentNotice();
        $level = $this->levelCode($user);
        $hidden = $this->hiddenFromAppTiles();

        $rows = [];

        foreach (Service::query()->enabled()->ordered()->get() as $service) {
            $type = $service->type();

            if ($type === null || in_array((int) $service->serviceid, $hidden, true)) {
                continue;
            }

            $rows[] = [
                'success' => 1,
                'status'  => 1,
                'service' => (string) $service->serviceid,
                'img'     => $this->serviceIcon((int) $service->serviceid),
                // The legacy endpoint relabelled bKash; keep it so the tile
                // matches what long-time users expect to see.
                'name'    => $type === ServiceType::Bkash ? 'B banking' : (string) $service->title,
                'message' => $balance,
                'notice'  => $notice,
                'level'   => $level,
                'act'     => $this->activityFor($type),
            ];

            // Flexiload also gets a companion "Drive pack" tile, which opens
            // the bundle picker rather than the amount keypad.
            if ($type === ServiceType::Flexiload && blank($request->input('item'))) {
                $rows[] = [
                    'success' => 1,
                    'status'  => 1,
                    'service' => '1',
                    'img'     => $this->serviceIcon('packag'),
                    'name'    => 'Drive pack',
                    'message' => $balance,
                    'notice'  => $notice,
                    'level'   => $level,
                    'act'     => 'drive',
                ];
            }
        }

        return $this->listResponse($rows);
    }

    /** GET /apiapp/notice */
    public function notices(): JsonResponse
    {
        $rows = Notice::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Notice $n) => [
                'id'      => (string) $n->id,
                'subject' => (string) $n->subject,
                'notice'  => trim(strip_tags((string) $n->notice)),
                'date'    => (string) $n->date,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/oparetorList */
    public function operators(): JsonResponse
    {
        $rows = Operator::query()
            ->where('status', 1)
            ->orderBy('title_op')
            ->get()
            ->map(fn (Operator $o) => [
                'id'       => (string) $o->id,
                'opname'   => (string) $o->opname,
                'title_op' => (string) $o->title_op,
                'prefix'   => (string) $o->pcode,
                'code'     => (string) $o->ex,
                'img'      => $this->serviceIcon(strtolower((string) $o->ex)),
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/countryList */
    public function countries(): JsonResponse
    {
        $rows = Country::query()
            ->where('status', 1)
            ->orderBy('country_name')
            ->get()
            ->map(fn (Country $c) => [
                'id'           => (string) $c->id,
                'country_name' => (string) $c->country_name,
                'code'         => (string) $c->code,
                'currency'     => (string) $c->currency,
                'phone'        => (string) $c->phone,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/relnumber - recently used numbers, for the quick picker. */
    public function savedNumbers(Request $request): JsonResponse
    {
        $user = $this->currentReseller($request);

        if ($user === null) {
            return $this->failList('Not Match.');
        }

        $rows = PhonebookEntry::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (PhonebookEntry $p) => [
                'number'  => (string) $p->mobile,
                'success' => 1,
                'status'  => 1,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/getinternet */
    public function internetPackages(Request $request): JsonResponse
    {
        $operator = trim((string) $request->input('op'));

        $rows = InternetPackage::query()
            ->where('status', 1)
            ->when($operator !== '', fn ($q) => $q->where('op', $operator))
            ->orderBy('price')
            ->get()
            ->map(fn (InternetPackage $p) => $this->packageRow(
                (int) $p->id, (string) $p->op, (string) $p->pk_name,
                (string) $p->volume, (float) $p->price, (float) $p->comm, (string) $p->exp,
            ))
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/getdrive */
    public function drivePackages(Request $request): JsonResponse
    {
        $operator = trim((string) $request->input('op'));

        $rows = DrivePackage::query()
            ->where('status', 1)
            ->when($operator !== '', fn ($q) => $q->where('op', $operator))
            ->orderBy('price')
            ->get()
            ->map(fn (DrivePackage $p) => $this->packageRow(
                (int) $p->id, (string) $p->op, (string) $p->pk_name,
                (string) $p->volume, (float) $p->price, (float) $p->comm, (string) $p->exp,
            ))
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/netSelect */
    public function internetOperators(): JsonResponse
    {
        $rows = InternetOperator::query()
            ->where('status', 1)
            ->orderBy('opname')
            ->get()
            ->map(fn (InternetOperator $o) => [
                'id'     => (string) $o->id,
                'opname' => (string) $o->opname,
                'prefix' => (string) $o->prefix,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/billpayProvider */
    public function billProviders(): JsonResponse
    {
        $rows = BillProvider::query()
            ->where('status', 1)
            ->orderBy('name')
            ->get()
            ->map(fn (BillProvider $b) => [
                'id'       => (string) $b->id,
                'name'     => (string) $b->name,
                'billcode' => (string) $b->billcode,
                'code'     => (string) $b->bill_code,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /** GET /apiapp/banklist */
    public function banks(): JsonResponse
    {
        $rows = BankName::query()
            ->where('status', 1)
            ->orderBy('bname')
            ->get()
            ->map(fn (BankName $b) => [
                'id'    => (string) $b->id,
                'bname' => (string) $b->bname,
                'code'  => (string) $b->bank_code,
                'swift' => (string) $b->swift,
            ])
            ->all();

        return $this->listResponse($rows);
    }

    /**
     * Shared package row. `reg` is the display line the client shows under the
     * title, and `com` the discount, both as plain strings.
     */
    private function packageRow(
        int $id,
        string $operator,
        string $title,
        string $volume,
        float $price,
        float $commission,
        string $expiry,
    ): array {
        return [
            'id'     => (string) $id,
            'reg'    => $volume !== '' ? $volume : $title,
            'title'  => $title,
            'price'  => number_format($price, 2, '.', ''),
            'com'    => number_format($commission, 2, '.', ''),
            'exp'    => $expiry,
            'opname' => $operator,
        ];
    }
}
