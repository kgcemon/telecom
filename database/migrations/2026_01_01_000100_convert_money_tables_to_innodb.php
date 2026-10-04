<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts the tables that carry money from MyISAM to InnoDB.
 *
 * Why this matters more than anything else in this migration set:
 *
 * MyISAM has no transactions and no row-level locking. The legacy recharge path
 * read a reseller balance, computed a new one in PHP, then wrote it back as a
 * separate statement. Two requests arriving together both read the same opening
 * balance, so the second write silently discarded the first debit - a reseller
 * could spend the same balance twice by firing concurrent requests. The same gap
 * let a crash land a debit with no matching ledger row, so statements and
 * balances drifted apart with no way to tell which was right.
 *
 * InnoDB gives us `SELECT ... FOR UPDATE` and real transactions, which is what
 * WalletService relies on to make a debit-plus-ledger-write atomic. Without this
 * migration that service cannot keep its guarantees.
 *
 * The conversion preserves all rows and columns; it rewrites each table in
 * place. Take a backup first - `php artisan telecom:backup` - and expect the
 * larger tables (login_attempts, user_online) to take a few seconds each.
 */
return new class extends Migration
{
    /**
     * Tables holding balances, ledger lines or transaction state. These need
     * transactional integrity and are converted first.
     */
    private const MONEY_TABLES = [
        'reseller',
        'sendflexi',
        'trans',
        'pay_receive',
        'bank_transfer',
        'bill_pay',
        'refil_card',
        'trnx',
        'payment_rquest',
        'card',
        'sms',
        'op_recharge',
        'invoice',
    ];

    /**
     * Supporting tables. Converting these is not required for correctness but
     * removes full-table write locks that stalled the panel under load.
     */
    private const SUPPORTING_TABLES = [
        'price',
        'rate_module',
        'rate_com',
        'com_price',
        'routing',
        'api_set',
        'module',
        'oparetor',
        'country_op',
        'tarif',
        'menu_list',
        'level_list',
        'security_option',
        'company',
        'general_settings',
        'device_list',
        'login_attempts',
        'user_online',
        'userlog',
        'activity_log',
        'apps_key',
        'api_key_user',
        'api_key_ip',
        'notice',
        'login_notice',
        'phonebook',
        'bulkflexi',
        'modem',
        'siminfo',
        'response',
        'response_from_provider',
        'gateway',
        'payment_gateway',
        'bank_name',
        'billing_set',
        'op_card',
        'op_card_amount',
        'net_op',
        'net_package',
        'drive_package',
        'sub_admin_brand',
        'ipblock',
        'reportserver',
        'tickets',
        'sms_server',
        'language',
        'language_list',
    ];

    public function up(): void
    {
        foreach ([...self::MONEY_TABLES, ...self::SUPPORTING_TABLES] as $table) {
            $this->convert($table);
        }
    }

    public function down(): void
    {
        // Deliberately not reverted. Going back to MyISAM would reintroduce the
        // double-spend window described above, so this migration is one-way. If
        // you genuinely need the old engine, restore from a backup instead.
    }

    private function convert(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $engine = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->value('ENGINE');

        if (strcasecmp((string) $engine, 'InnoDB') === 0) {
            return;
        }

        // Quote with backticks; table names here are from a fixed allow-list above.
        DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
    }
};
