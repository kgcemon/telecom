<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every NOT NULL column a usable default.
 *
 * The legacy schema declares around 200 columns NOT NULL with no default, and
 * the legacy inserts simply omitted most of them. That only worked because the
 * server runs without STRICT_TRANS_TABLES: MySQL quietly substituted '' or 0
 * and carried on. The same permissiveness is why bad data accumulated - a
 * truncated value or a non-numeric amount was coerced instead of rejected.
 *
 * Laravel enables strict mode per connection, which is the behaviour we want:
 * an out-of-range amount should fail loudly rather than silently become
 * something else. Keeping strict mode means the schema has to be honest about
 * its own defaults, which is what this migration does.
 *
 * Defaults chosen are the values the server was substituting anyway, so
 * existing rows and the legacy application are unaffected.
 */
return new class extends Migration
{
    /** Legacy tables that are written to by the application. */
    private const TABLES = [
        'reseller', 'sendflexi', 'trans', 'pay_receive', 'bank_transfer', 'bill_pay',
        'refil_card', 'card', 'sms', 'op_recharge', 'payment_rquest', 'trnx',
        'price', 'rate_module', 'rate_com', 'com_price', 'routing', 'api_set',
        'module', 'oparetor', 'country', 'country_op', 'tarif', 'menu_list',
        'level_list', 'security_option', 'company', 'device_list', 'login_attempts',
        'userlog', 'activity_log', 'user_online', 'apps_key', 'api_key_user',
        'api_key_ip', 'notice', 'login_notice', 'phonebook', 'bulkflexi', 'modem',
        'siminfo', 'response', 'response_from_provider', 'gateway', 'payment_gateway',
        'bank_name', 'billing_set', 'op_card', 'op_card_amount', 'net_op',
        'net_package', 'drive_package', 'sub_admin_brand', 'ipblock', 'reportserver',
        'tickets', 'tricket_main', 'complain', 'block_list', 'invoice',
    ];

    /**
     * Columns that must stay without a default because a caller is always
     * required to supply them. Leaving these strict is a safety feature: an
     * insert that forgets a username or an amount should fail, not default.
     */
    private const LEAVE_STRICT = [
        'reseller' => ['username', 'password'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->columnsNeedingDefaults($table) as $column) {
                if (in_array($column->COLUMN_NAME, self::LEAVE_STRICT[$table] ?? [], true)) {
                    continue;
                }

                $default = $this->defaultFor($column->DATA_TYPE);

                if ($default === null) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE `%s` ALTER COLUMN `%s` SET DEFAULT %s',
                    $table,
                    $column->COLUMN_NAME,
                    $default,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->columnsWithAddedDefaults($table) as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` ALTER COLUMN `%s` DROP DEFAULT',
                    $table,
                    $column->COLUMN_NAME,
                ));
            }
        }
    }

    /** NOT NULL, no default, not generated, not auto-increment. */
    private function columnsNeedingDefaults(string $table): array
    {
        return DB::table('information_schema.COLUMNS')
            ->select('COLUMN_NAME', 'DATA_TYPE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('IS_NULLABLE', 'NO')
            ->whereNull('COLUMN_DEFAULT')
            ->where('EXTRA', 'NOT LIKE', '%auto_increment%')
            ->where('EXTRA', 'NOT LIKE', '%GENERATED%')
            ->get()
            ->all();
    }

    private function columnsWithAddedDefaults(string $table): array
    {
        return DB::table('information_schema.COLUMNS')
            ->select('COLUMN_NAME', 'DATA_TYPE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('IS_NULLABLE', 'NO')
            ->whereNotNull('COLUMN_DEFAULT')
            ->whereIn('COLUMN_DEFAULT', ['', '0', '0.00', '0.000'])
            ->where('EXTRA', 'NOT LIKE', '%auto_increment%')
            ->get()
            ->all();
    }

    /**
     * The literal the server would have substituted, so behaviour is unchanged.
     * Date and binary types return null and are left alone - a meaningful
     * default cannot be guessed for them, and the application always supplies
     * a value.
     */
    private function defaultFor(string $dataType): ?string
    {
        return match (strtolower($dataType)) {
            'varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set' => "''",
            'int', 'tinyint', 'smallint', 'mediumint', 'bigint' => '0',
            'decimal', 'numeric', 'float', 'double' => '0',
            default => null,
        };
    }
};
