<?php
/**
 * One concurrent debit attempt. Run many of these at once against the same
 * reseller to prove WalletService serialises them correctly.
 *
 * usage: php concurrency_worker.php <resellerId> <amount> [mode]
 *   mode = safe   -> WalletService (locked)
 *   mode = legacy -> reproduction of the old read-compute-write (unlocked)
 */

require getenv('LAR_ROOT') . '/vendor/autoload.php';

$app = require getenv('LAR_ROOT') . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\WalletType;
use App\Services\Wallet\Exceptions\InsufficientBalanceException;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

$resellerId = (int) ($argv[1] ?? 0);
$amount     = (float) ($argv[2] ?? 0);
$mode       = $argv[3] ?? 'safe';

// Start all workers at the same wall-clock moment so they genuinely collide.
$startAt = (float) (getenv('START_AT') ?: 0);
if ($startAt > 0) {
    while (microtime(true) < $startAt) {
        usleep(200);
    }
}

try {
    if ($mode === 'legacy') {
        // The original pattern: read, compute in PHP, write back. No lock,
        // no transaction. Included only to demonstrate the bug it caused.
        $balance = (float) DB::table('reseller')->where('id', $resellerId)->value('balance');

        if ($balance >= $amount) {
            usleep(5000); // the window the legacy code left open
            DB::table('reseller')->where('id', $resellerId)->update(['balance' => $balance - $amount]);
            fwrite(STDOUT, "OK\n");
            exit(0);
        }

        fwrite(STDOUT, "DECLINED\n");
        exit(0);
    }

    app(WalletService::class)->debit(
        $resellerId,
        WalletType::Main,
        $amount,
        'concurrency test debit',
        ['type' => 'test']
    );

    fwrite(STDOUT, "OK\n");
    exit(0);
} catch (InsufficientBalanceException $e) {
    fwrite(STDOUT, "DECLINED\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
