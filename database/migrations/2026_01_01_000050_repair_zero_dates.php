<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs `0000-00-00 00:00:00` values before the InnoDB conversion.
 *
 * MyISAM under a permissive sql_mode accepted the zero date, so a handful of
 * `sendflexi` rows were written with no submitted_date at all. Converting the
 * table to InnoDB under a strict sql_mode rejects those rows outright, which is
 * what blocked the engine change.
 *
 * Rather than stamping them with the current time and losing the real ordering,
 * the value is recovered from `actual_send_time` - a varchar column that holds a
 * proper `Y-m-d H:i:s` for every affected row - and falls back to `idate` (the
 * date-only column) when that is missing. Only rows that are actually broken are
 * touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sendflexi') || ! Schema::hasColumn('sendflexi', 'submitted_date')) {
            return;
        }

        // Allow reading and rewriting the invalid value within this session only.
        $previousMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''");

        try {
            // 1. Recover from actual_send_time when it parses as a real datetime.
            DB::statement(<<<'SQL'
                UPDATE sendflexi
                   SET submitted_date = STR_TO_DATE(actual_send_time, '%Y-%m-%d %H:%i:%s')
                 WHERE CAST(submitted_date AS CHAR) LIKE '0000-00-00%'
                   AND actual_send_time IS NOT NULL
                   AND STR_TO_DATE(actual_send_time, '%Y-%m-%d %H:%i:%s') IS NOT NULL
            SQL);

            // 2. Fall back to the date-only column, at midnight.
            DB::statement(<<<'SQL'
                UPDATE sendflexi
                   SET submitted_date = STR_TO_DATE(CONCAT(idate, ' 00:00:00'), '%Y-%m-%d %H:%i:%s')
                 WHERE CAST(submitted_date AS CHAR) LIKE '0000-00-00%'
                   AND idate IS NOT NULL
                   AND STR_TO_DATE(idate, '%Y-%m-%d') IS NOT NULL
            SQL);

            // 3. Anything still broken has no recoverable date; pin it to the
            //    epoch-ish sentinel so it is obviously synthetic rather than
            //    looking like a recent request.
            DB::statement(<<<'SQL'
                UPDATE sendflexi
                   SET submitted_date = '1970-01-01 00:00:01'
                 WHERE CAST(submitted_date AS CHAR) LIKE '0000-00-00%'
            SQL);

            $remaining = (int) DB::scalar(
                "SELECT COUNT(*) FROM sendflexi WHERE CAST(submitted_date AS CHAR) LIKE '0000-00-00%'"
            );

            if ($remaining > 0) {
                throw new RuntimeException(
                    "Could not repair {$remaining} zero-date row(s) in sendflexi.submitted_date."
                );
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previousMode]);
        }
    }

    public function down(): void
    {
        // Not reversible: the original values carried no information.
    }
};
