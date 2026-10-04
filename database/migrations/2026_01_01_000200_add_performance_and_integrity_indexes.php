<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the indexes the legacy schema never had.
 *
 * The table that matters most is `reseller`: it had an index on `id` only, so
 * every single login ran a full table scan to find a username. With the
 * AUTO_INCREMENT already past 213 000 that is the difference between a
 * millisecond and a full sweep of the table on each attempt - and it also made
 * credential-stuffing cheap to turn into a denial of service, because each
 * guess cost a scan.
 *
 * `reseller.username` also gets a UNIQUE constraint where the data allows it.
 * Without one, two accounts could share a username and which one a login
 * resolved to depended on row order, which is an authentication correctness
 * problem rather than a performance one. If duplicates already exist the
 * migration falls back to a plain index and reports them, so a live database is
 * never left half-migrated.
 */
return new class extends Migration
{
    /**
     * table => [index name => columns]
     */
    private const INDEXES = [
        'reseller' => [
            'idx_reseller_parent'  => ['p_id'],
            'idx_reseller_custype' => ['custype', 'status'],
            'idx_reseller_status'  => ['status', 'delete'],
            'idx_reseller_mobile'  => ['mobile'],
            'idx_reseller_tarif'   => ['tarif'],
            'idx_reseller_level1'  => ['level1'],
        ],
        'sendflexi' => [
            'idx_flexi_user_date'   => ['userid', 'idate'],
            'idx_flexi_status'      => ['status', 'local'],
            'idx_flexi_service'     => ['service', 'status'],
            'idx_flexi_sid'         => ['sid'],
            'idx_flexi_phone'       => ['phone'],
            'idx_flexi_idate'       => ['idate'],
            'idx_flexi_parent'      => ['p_id', 'idate'],
            'idx_flexi_trxid'       => ['trxid'],
            'idx_flexi_dispatch'    => ['status', 'service', 'pcode'],
        ],
        'trans' => [
            'idx_trans_user_date' => ['userid', 'date'],
            'idx_trans_flex'      => ['flex_id'],
            'idx_trans_send_sl'   => ['send_sl'],
        ],
        'pay_receive' => [
            'idx_pay_user'   => ['userid', 'idate'],
            'idx_pay_parent' => ['p_id', 'idate'],
            'idx_pay_trxid'  => ['trxid'],
        ],
        'price' => [
            'idx_price_lookup' => ['service', 'prefix', 'type'],
            'idx_price_pcode'  => ['service', 'pcode'],
        ],
        'rate_module' => [
            'idx_rate_lookup' => ['tarif', 'service', 'pcode'],
            'idx_rate_user'   => ['userid', 'service'],
        ],
        'rate_com' => [
            'idx_ratecom_lookup' => ['tarif', 'service', 'pcode'],
        ],
        'com_price' => [
            'idx_comprice_lookup' => ['service', 'prefix', 'price'],
        ],
        'routing' => [
            'idx_routing_pick' => ['service', 'pcode', 'active', 'status', 'priority'],
        ],
        'login_attempts' => [
            'idx_attempt_ip'    => ['ip_address', 'time'],
            'idx_attempt_login' => ['login', 'time'],
        ],
        'apps_key' => [
            'idx_appskey_user' => ['userid', 'status'],
        ],
        'device_list' => [
            'idx_device_user'    => ['userid', 'status'],
            'idx_device_session' => ['session'],
        ],
        'user_online' => [
            'idx_online_time' => ['time'],
            'idx_online_user' => ['userid'],
        ],
        'trnx' => [
            'idx_trnx_status' => ['status', 'paid'],
            'idx_trnx_user'   => ['userid'],
        ],
        'bank_transfer' => [
            'idx_bank_user'   => ['user_id', 'date'],
            'idx_bank_status' => ['status'],
        ],
        'bill_pay' => [
            'idx_billpay_user'   => ['user_id', 'date'],
            'idx_billpay_status' => ['status'],
        ],
        'refil_card' => [
            'idx_card_stock' => ['op', 'amount', 'status'],
            'idx_card_user'  => ['userid'],
        ],
        'menu_list' => [
            'idx_menu_user' => ['userid'],
        ],
        'activity_log' => [
            'idx_activity_user' => ['userid'],
        ],
        'userlog' => [
            'idx_userlog_date' => ['log_date'],
        ],
        'response' => [
            'idx_response_order' => ['id_order'],
        ],
        'tickets' => [
            'idx_ticket_thread' => ['tricket_id'],
        ],
        'phonebook' => [
            'idx_phonebook_user' => ['user_id', 'gid'],
        ],
    ];

    /** Unique keys to add when the existing data permits it. */
    private const UNIQUE_CANDIDATES = [
        'reseller' => ['uniq_reseller_username' => ['username']],
        'apps_key' => ['uniq_appskey_token' => ['token']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns, unique: false);
            }
        }

        foreach (self::UNIQUE_CANDIDATES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if ($this->hasDuplicates($table, $columns)) {
                    // Fall back to a plain index so the lookup is still fast,
                    // and tell the operator what to clean up.
                    $this->addIndex($table, str_replace('uniq_', 'idx_', $name), $columns, unique: false);

                    $this->warn(sprintf(
                        'Skipped UNIQUE %s on %s(%s): duplicate values exist. '
                        .'Resolve them, then run: php artisan telecom:enforce-unique %s %s',
                        $name, $table, implode(', ', $columns), $table, implode(',', $columns),
                    ));

                    continue;
                }

                $this->addIndex($table, $name, $columns, unique: true);
            }
        }
    }

    public function down(): void
    {
        $all = self::INDEXES;

        foreach (self::UNIQUE_CANDIDATES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $all[$table][$name] = $columns;
                $all[$table][str_replace('uniq_', 'idx_', $name)] = $columns;
            }
        }

        foreach ($all as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }

    private function addIndex(string $table, string $name, array $columns, bool $unique): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        if ($this->indexExists($table, $name)) {
            return;
        }

        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

        DB::statement("ALTER TABLE `{$table}` ADD {$type} `{$name}` ({$cols})");
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function hasDuplicates(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return true;
            }
        }

        return DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function warn(string $message): void
    {
        if (app()->runningInConsole() && function_exists('app') && app()->bound('log')) {
            logger()->warning('[migration] '.$message);
        }

        fwrite(STDERR, '  ! '.$message.PHP_EOL);
    }
};
