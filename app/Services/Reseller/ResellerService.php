<?php

declare(strict_types=1);

namespace App\Services\Reseller;

use App\Models\MenuPermission;
use App\Models\Reseller;
use App\Models\Tariff;
use App\Services\Security\LegacyHasher;
use App\Services\Security\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creating and maintaining reseller accounts.
 *
 * Account creation touches four tables - the account, its tariff, its
 * permission row and the denormalised ancestor columns - so it runs in a
 * transaction. The legacy version issued those inserts separately and a failure
 * part-way through left an account with no tariff, which then priced every
 * request at zero.
 */
class ResellerService
{
    public function __construct(
        private readonly LegacyHasher $hasher,
        private readonly HierarchyService $hierarchy,
        private readonly PermissionService $permissions,
    ) {}

    public function create(Reseller $parent, array $data): Reseller
    {
        return DB::transaction(function () use ($parent, $data): Reseller {
            $now = Carbon::now();
            $ancestors = $this->hierarchy->ancestorChainFor($parent);

            $reseller = Reseller::create([
                'username' => $data['username'],
                'password' => $this->hasher->make($data['password']),
                'pincode'  => $this->hasher->make($data['pincode']),
                'type'     => '1',
                'name'     => $data['name'],
                'mobile'   => $data['mobile'],
                'email'    => $data['email'] ?? '',
                'note'     => $data['note'] ?? '',
                'custype'  => $data['custype'],
                'p_id'     => (int) $parent->id,
                'admin_id' => (string) ($parent->admin_id ?: $parent->id),

                'balance'       => 0,
                'bank_balance'  => 0,
                'drive_bal'     => 0,
                'balance_limit' => (float) ($data['balance_limit'] ?? 0),

                'create_date' => $now->format('Y-m-d H:i:s'),
                'ip'          => request()?->ip() ?? '',
                'status'      => 1,
                'login_allow' => 1,
                'recharge'    => 1,
                'reseller'    => 1,
                'delete'      => 0,
                'webAccess'   => ! empty($data['webAccess']) ? 1 : 0,
                'appsAccess'  => ! empty($data['appsAccess']) ? 1 : 0,

                'lft' => 0,
                'rgt' => 0,

                // Denormalised ancestors. The legacy table stores this chain
                // backwards - level5 is the immediate parent and level1 the
                // most distant - so the order here is deliberate, not a slip.
                'level5' => (string) ($ancestors[0] ?? ''),
                'level4' => (string) ($ancestors[1] ?? ''),
                'level3' => (string) ($ancestors[2] ?? ''),
                'level2' => (string) ($ancestors[3] ?? ''),
                'level1' => (string) ($ancestors[4] ?? ''),

                'enbale_otp'  => 0,
                'tempor_otp'  => 0,
                'otp_choice'  => 0,
                'otp'         => '',
                'gotp_key'    => '',
                'de_pin'      => '',
                'de_pass'     => '',
                'last_login'  => '',
                'login_count' => '0',
                'domain_name' => '',
                'device_safe' => 0,
                'device_lock' => 0,
                'time'        => 0,
                'chnage_pin_d'  => $now->format('Y-m-d'),
                'chnage_pin_t'  => $now->format('H:i:s'),
                'chnage_pass_d' => $now->format('Y-m-d'),
                'chnage_pass_t' => $now->format('H:i:s'),

                // Assigned below once we know whether a tariff was supplied.
                'tarif' => '',
            ]);

            // Every account needs a tariff, or pricing has nothing to resolve
            // against. Reuse the chosen one, else clone one for this account.
            $tariffId = $data['tarif'] ?? null;

            if ($tariffId === null) {
                $tariffId = $this->createTariffFor($reseller, $parent);
            }

            $reseller->forceFill(['tarif' => (string) $tariffId])->save();

            // Start from the level defaults rather than no permissions at all.
            $this->permissions->sync($reseller, $this->defaultPermissionKeys($reseller));

            return $reseller->refresh();
        });
    }

    /**
     * Apply an edit.
     *
     * @return array<int, string> names of the fields that actually changed
     */
    public function update(Reseller $reseller, array $data): array
    {
        $now = Carbon::now();
        $payload = [];

        foreach (['name', 'mobile', 'email', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? '';
            }
        }

        if (array_key_exists('tarif', $data) && $data['tarif'] !== null) {
            $payload['tarif'] = (string) $data['tarif'];
        }

        if (array_key_exists('balance_limit', $data)) {
            $payload['balance_limit'] = (float) ($data['balance_limit'] ?? 0);
        }

        foreach (['status', 'login_allow', 'webAccess', 'appsAccess', 'recharge', 'device_lock', 'api'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $payload[$flag] = ! empty($data[$flag]) ? 1 : 0;
            }
        }

        // Credentials are only touched when a new value was actually supplied,
        // so saving the form does not silently reset a password to blank.
        if (filled($data['password'] ?? null)) {
            $payload['password'] = $this->hasher->make($data['password']);
            $payload['chnage_pass_d'] = $now->format('Y-m-d');
            $payload['chnage_pass_t'] = $now->format('H:i:s');
        }

        if (filled($data['pincode'] ?? null)) {
            $payload['pincode'] = $this->hasher->make($data['pincode']);
            $payload['chnage_pin_d'] = $now->format('Y-m-d');
            $payload['chnage_pin_t'] = $now->format('H:i:s');
        }

        if ($payload === []) {
            return [];
        }

        $reseller->forceFill($payload)->save();

        $this->permissions->forget((int) $reseller->id);

        // Report field names only; never the credential values themselves.
        return array_values(array_map(
            fn (string $k) => in_array($k, ['password', 'pincode'], true) ? $k.' (reset)' : $k,
            array_keys($payload),
        ));
    }

    /**
     * Flag the account deleted and revoke its access.
     *
     * The row stays so ledger and transaction history keep referential meaning.
     */
    public function softDelete(Reseller $reseller): void
    {
        DB::transaction(function () use ($reseller): void {
            $reseller->forceFill([
                'delete'      => 1,
                'status'      => 0,
                'login_allow' => 0,
                'webAccess'   => 0,
                'appsAccess'  => 0,
                'recharge'    => 0,
            ])->save();

            // Invalidate anything that could still authenticate as them.
            DB::table('device_list')->where('userid', (string) $reseller->id)->update(['status' => 0]);
            DB::table('apps_key')->where('userid', $reseller->id)->update(['status' => 0]);

            $this->permissions->forget((int) $reseller->id);
        });
    }

    /** Clone the parent tariff so a new account starts on the same prices. */
    private function createTariffFor(Reseller $reseller, Reseller $parent): int
    {
        $tariff = Tariff::create([
            'make_by' => (string) $parent->id,
            'name'    => $reseller->username.' Tariff',
            'desc'    => 'Created automatically for '.$reseller->username,
            'userid'  => (string) $reseller->id,
            'duble'   => (string) ($parent->tarif ?: ''),
            'date'    => now()->format('Y-m-d'),
            'time'    => now()->format('H:i:s'),
        ]);

        // Copy the parent rate rows so the new account is priced from day one.
        if (filled($parent->tarif)) {
            $rows = DB::table('rate_module')->where('tarif', $parent->tarif)->get();

            foreach ($rows->chunk(200) as $chunk) {
                $insert = [];

                foreach ($chunk as $row) {
                    $copy = (array) $row;
                    unset($copy['id']);
                    $copy['tarif'] = (string) $tariff->id;
                    $copy['userid'] = (string) $reseller->id;
                    $copy['create_by'] = (string) $parent->id;
                    $copy['date'] = now()->format('Y-m-d');
                    $insert[] = $copy;
                }

                if ($insert !== []) {
                    DB::table('rate_module')->insert($insert);
                }
            }
        }

        return (int) $tariff->id;
    }

    private function defaultPermissionKeys(Reseller $reseller): array
    {
        return array_keys(array_filter($this->permissions->flagsFor($reseller)));
    }
}
