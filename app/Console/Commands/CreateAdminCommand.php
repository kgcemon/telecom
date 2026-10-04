<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ResellerLevel;
use App\Models\Reseller;
use App\Services\Security\LegacyHasher;
use App\Services\Security\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create an administrator, or reset an existing one's credentials.
 *
 * Needed on a fresh deployment, and as the recovery path when nobody can sign
 * in. Credentials are prompted for rather than passed as arguments by default,
 * so they do not end up in the shell history or the process list.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'telecom:admin
        {username? : Account username}
        {--reset : Reset the password and PIN of an existing account}
        {--level=admin : admin or subadmin}
        {--password= : Non-interactive password; prefer the env var TELECOM_ADMIN_PASSWORD}
        {--pin= : Non-interactive PIN; prefer the env var TELECOM_ADMIN_PIN}';

    protected $description = 'Create an administrator account, or reset one that already exists';

    public function handle(LegacyHasher $hasher, PermissionService $permissions): int
    {
        $username = $this->argument('username') ?: $this->ask('Username');
        $username = trim((string) $username);

        $existing = Reseller::query()->where('username', $username)->first();

        if ($existing !== null && ! $this->option('reset')) {
            $this->error("An account named \"{$username}\" already exists. Pass --reset to change its credentials.");

            return self::FAILURE;
        }

        if ($existing === null && $this->option('reset')) {
            $this->error("No account named \"{$username}\" was found.");

            return self::FAILURE;
        }

        $level = ResellerLevel::tryFrom((string) $this->option('level'));

        if ($level === null || ! $level->isAdministrative()) {
            $this->error('The level must be admin or subadmin.');

            return self::FAILURE;
        }

        // Prefer the environment over an option: an option is visible in the
        // shell history and in the process list of anyone on the machine.
        $password = (string) ($this->option('password') ?: env('TELECOM_ADMIN_PASSWORD', ''));

        if ($password === '') {
            $password = (string) $this->secret('Password (at least 8 characters)');

            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        } elseif ($this->option('password')) {
            $this->warn('Passing --password leaves the credential in your shell history. Prefer TELECOM_ADMIN_PASSWORD.');
        }

        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        $pin = (string) ($this->option('pin') ?: env('TELECOM_ADMIN_PIN', ''));

        if ($pin === '') {
            $pin = (string) $this->secret('Transaction PIN (digits only, at least 4)');
        }

        if (! ctype_digit((string) $pin) || strlen((string) $pin) < 4) {
            $this->error('The PIN must be at least 4 digits.');

            return self::FAILURE;
        }

        if ($existing !== null) {
            DB::table('reseller')->where('id', $existing->id)->update([
                'password'      => $hasher->make($password),
                'pincode'       => $hasher->make($pin),
                'status'        => 1,
                'login_allow'   => 1,
                'delete'        => 0,
                'chnage_pass_d' => now()->format('Y-m-d'),
                'chnage_pass_t' => now()->format('H:i:s'),
            ]);

            $this->info("Credentials reset for \"{$username}\" (id {$existing->id}).");

            return self::SUCCESS;
        }

        $validator = validator(['username' => $username], [
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('reseller', 'username')],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $reseller = DB::transaction(function () use ($username, $password, $pin, $level, $hasher): Reseller {
            $now = now();

            return Reseller::create([
                'username' => $username,
                'password' => $hasher->make($password),
                'pincode'  => $hasher->make($pin),
                'type'     => '1',
                'name'     => $username,
                'mobile'   => '',
                'email'    => '',
                'note'     => 'Created via telecom:admin',
                'custype'  => $level->value,
                'p_id'     => 1,
                'admin_id' => '1',

                'balance' => 0, 'bank_balance' => 0, 'drive_bal' => 0, 'balance_limit' => 0,
                'lft' => 0, 'rgt' => 0,

                'create_date' => $now->format('Y-m-d H:i:s'),
                'ip' => '', 'status' => 1, 'login_allow' => 1, 'delete' => 0,
                'webAccess' => 1, 'appsAccess' => 1, 'recharge' => 1, 'reseller' => 1,

                'enbale_otp' => 0, 'tempor_otp' => 0, 'otp_choice' => 0, 'otp' => '',
                'gotp_key' => '', 'de_pin' => '', 'de_pass' => '',
                'last_login' => '', 'login_count' => '0', 'tarif' => '', 'domain_name' => '',
                'device_safe' => 0, 'device_lock' => 0, 'time' => 0,
                'chnage_pin_d' => $now->format('Y-m-d'),
                'chnage_pin_t' => $now->format('H:i:s'),
                'chnage_pass_d' => $now->format('Y-m-d'),
                'chnage_pass_t' => $now->format('H:i:s'),
            ]);
        });

        $permissions->sync($reseller, array_keys(PermissionService::PERMISSIONS));

        $this->info("Created {$level->value} \"{$username}\" (id {$reseller->id}).");
        $this->line('Sign in at /admin/login');

        return self::SUCCESS;
    }
}
