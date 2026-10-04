<?php
/**
 * End-to-end check of the recharge pipeline against the real schema.
 * Creates a disposable reseller, exercises the engine, verifies the books,
 * then removes everything it created.
 */

require getenv('LAR_ROOT') . '/vendor/autoload.php';
$app = require getenv('LAR_ROOT') . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Enums\WalletType;
use App\Models\LedgerEntry;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Services\Recharge\Dto\RechargeInput;
use App\Services\Recharge\OperatorResolver;
use App\Services\Recharge\PricingService;
use App\Services\Recharge\RechargeService;
use App\Services\Security\PinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', strlen($t)) . "\n"; }

// Don't actually call providers during the test.
Queue::fake();

// ---------------------------------------------------------------- setup
$TEST_USER = '__rechargetest__';
DB::table('reseller')->where('username', $TEST_USER)->delete();

$pins = app(PinService::class);
$hasher = app(App\Services\Security\LegacyHasher::class);

$id = DB::table('reseller')->insertGetId([
    'username' => $TEST_USER, 'password' => $hasher->make('pw'),
    'pincode' => $hasher->make('8273'), 'type' => '1',
    'name' => 'Recharge Test', 'mobile' => '01700000000', 'email' => '', 'note' => '',
    'custype' => 'reseller1', 'p_id' => 1, 'admin_id' => '1', 'lft' => 0, 'rgt' => 0,
    'bank_balance' => 5000, 'balance' => 5000, 'drive_bal' => 5000, 'balance_limit' => 0,
    'create_date' => now(), 'ip' => '127.0.0.1', 'enbale_otp' => 0, 'tempor_otp' => 0,
    'last_login' => '', 'login_count' => '0', 'tarif' => '1', 'domain_name' => '',
    'status' => 1, 'login_allow' => 1, 'device_safe' => 0, 'time' => 0, 'delete' => 0,
    'chnage_pin_d' => '', 'chnage_pin_t' => '', 'chnage_pass_d' => '', 'chnage_pass_t' => '',
    'de_pin' => '', 'de_pass' => '', 'gotp_key' => '', 'otp_choice' => 0, 'otp' => '',
    'webAccess' => 1, 'appsAccess' => 1, 'recharge' => 1,
]);

$reseller = Reseller::find($id);
$recharge = app(RechargeService::class);
$pricing  = app(PricingService::class);
$ops      = app(OperatorResolver::class);

echo "Disposable reseller id={$id}, balances: main=5000 bank=5000 drive=5000\n";

// ------------------------------------------------- operator resolution
section('Operator resolution');
foreach ([
    ['01712345678', '017', 'GP'],
    ['8801812345678', '018', 'RB'],
    ['+8801912345678', '019', 'BL'],
    ['01612345678', '016', 'AT'],
    ['01512345678', '015', 'TT'],
    ['01312345678', '013', 'GP'],   // missed by the legacy mapping
    ['01412345678', '014', 'BL'],   // missed by the legacy mapping
] as [$num, $expPrefix, $expCode]) {
    $prefix = $ops->prefixOf($num);
    $code = $ops->resolve($num);
    check("$num -> $expPrefix/$expCode", $prefix === $expPrefix && $code === $expCode, "got $prefix/$code");
}
check('normalises to national format', $ops->normaliseNumber('+8801712345678') === '01712345678',
    $ops->normaliseNumber('+8801712345678'));

// ------------------------------------------------------------ pricing
section('Pricing');
$q = $pricing->quote($reseller, ServiceType::Flexiload, 100, '01712345678', '1');
check('GP 100 flexiload resolves a price', $q->resolved, $q->source);
check('GP 100 payable = 100 (0% comm/charge)', abs($q->payable() - 100.0) < 0.001, (string) $q->payable());

// Teletalk carries charge = 1.5%
$qt = $pricing->quote($reseller, ServiceType::Flexiload, 100, '01512345678', '1');
check('TT 100 payable = 101.50 (1.5% charge)', abs($qt->payable() - 101.5) < 0.001, (string) $qt->payable());

// Unpriced service must be reported, not treated as free
$qu = $pricing->quote($reseller, ServiceType::Bpo, 100, '01712345678', '1');
check('unpriced service flagged unresolved', ! $qu->resolved, 'source=' . $qu->source);
check('unpriced quote still charges face value, not zero', $qu->payable() >= 100.0, (string) $qu->payable());

// Bill-pay tiered fee bands
foreach ([[400, 5], [401, 10], [1500, 10], [1501, 15], [5000, 15], [5001, 25]] as [$amt, $fee]) {
    $qb = $pricing->quote($reseller, ServiceType::BillPay2, (float) $amt, '0171234567', '1');
    check("billpay2 $amt tiered fee = $fee", abs($qb->tieredFee - $fee) < 0.001, (string) $qb->tieredFee);
}

// ------------------------------------------------- successful recharge
section('Successful recharge');
$before = (float) Reseller::find($id)->balance;
$beforeBank = (float) Reseller::find($id)->bank_balance;

$out = $recharge->place(new RechargeInput(
    reseller: $reseller, service: ServiceType::Flexiload,
    number: '01712345678', amount: 100, numberType: '1',
    pin: '8273', channel: 'web', ipAddress: '127.0.0.1',
));

check('request accepted', $out->successful, $out->message . ' / ' . (string) $out->reasonCode);

if ($out->successful) {
    $req = $out->request->fresh();
    $after = (float) Reseller::find($id)->balance;

    check('sendflexi row created', $req !== null);
    check('face amount stored = 100', (int) $req->balance === 100, (string) $req->balance);
    check('cost stored = 100', abs((float) $req->cost - 100.0) < 0.001, (string) $req->cost);
    check('operator resolved to gp', $req->operator === 'gp', (string) $req->operator);
    check('pcode stored = GP', $req->pcode === 'GP', (string) $req->pcode);
    check('status = Pending (auto, 100 <= auto_limit 1000)',
        $req->status === TransactionStatus::Pending, $req->status->label());
    check('wallet recorded', $req->result === WalletType::Bank->value || $req->result === WalletType::Main->value,
        (string) $req->result);

    $walletCol = $req->result === 'bank' ? 'bank_balance' : 'balance';
    $openBal = $req->result === 'bank' ? $beforeBank : $before;
    $nowBal = (float) Reseller::find($id)->{$walletCol};
    check('wallet debited by exactly 100', abs(($openBal - $nowBal) - 100.0) < 0.001,
        sprintf('%.2f -> %.2f', $openBal, $nowBal));

    $entry = LedgerEntry::where('flex_id', $req->sid)->first();
    check('ledger line written', $entry !== null);
    if ($entry) {
        check('ledger debit = 100', abs((float) $entry->debit - 100.0) < 0.001, (string) $entry->debit);
        check('ledger opening matches wallet before', abs((float) $entry->oldbal - $openBal) < 0.001,
            sprintf('%.2f vs %.2f', (float) $entry->oldbal, $openBal));
        check('ledger closing matches wallet after', abs((float) $entry->accountbalance - $nowBal) < 0.001,
            sprintf('%.2f vs %.2f', (float) $entry->accountbalance, $nowBal));
        check('ledger links to request', (int) $entry->send_sl === (int) $req->id);
    }
    check('dispatch job queued', Queue::pushed(App\Jobs\DispatchRechargeRequest::class)->isNotEmpty());
}

// --------------------------------------------------------- PIN bypass
section('PIN enforcement (legacy bypasses must be closed)');
foreach (['api', 'ets', 'offline'] as $magic) {
    $o = $recharge->place(new RechargeInput(
        reseller: Reseller::find($id), service: ServiceType::Flexiload,
        number: '01798765432', amount: 50, pin: $magic, channel: 'web',
    ));
    check("magic PIN '$magic' rejected", ! $o->successful, $o->reasonCode . ': ' . $o->message);
}
$o = $recharge->place(new RechargeInput(
    reseller: Reseller::find($id), service: ServiceType::Flexiload,
    number: '01798765432', amount: 50, pin: null, channel: 'web',
));
check('missing PIN rejected', ! $o->successful, (string) $o->reasonCode);
app(PinService::class)->clearAttempts(Reseller::find($id));

// ------------------------------------------------------- validation
section('Validation');
$cases = [
    ['below service minimum', 1, '01712345678', 'amount_below_min'],
    ['above service maximum', 999999, '01712345678', 'amount_above_ceiling'],
    ['number too short', 100, '0171', 'number_too_short'],
    ['unsupported operator', 100, '01212345678', 'operator_unknown'],
    ['zero amount', 0, '01712345678', 'amount_invalid'],
    ['negative amount', -100, '01712345678', 'amount_invalid'],
];
foreach ($cases as [$label, $amt, $num, $expected]) {
    $o = $recharge->place(new RechargeInput(
        reseller: Reseller::find($id), service: ServiceType::Flexiload,
        number: $num, amount: (float) $amt, pin: '8273', channel: 'web',
    ));
    check("$label rejected", ! $o->successful, $o->reasonCode . ': ' . $o->message);
    app(PinService::class)->clearAttempts(Reseller::find($id));
}

// duplicate guard
section('Duplicate guard');
$a = $recharge->place(new RechargeInput(
    reseller: Reseller::find($id), service: ServiceType::Flexiload,
    number: '01733333333', amount: 60, pin: '8273', channel: 'web',
));
$b = $recharge->place(new RechargeInput(
    reseller: Reseller::find($id), service: ServiceType::Flexiload,
    number: '01733333333', amount: 60, pin: '8273', channel: 'web',
));
check('first of identical pair accepted', $a->successful, (string) $a->reasonCode);
check('immediate duplicate rejected', ! $b->successful && $b->reasonCode === 'duplicate_request',
    (string) $b->reasonCode);

// -------------------------------------------------- insufficient funds
section('Insufficient balance');
DB::table('reseller')->where('id', $id)->update(['balance' => 10, 'bank_balance' => 10, 'drive_bal' => 10]);
$o = $recharge->place(new RechargeInput(
    reseller: Reseller::find($id), service: ServiceType::Flexiload,
    number: '01744444444', amount: 500, pin: '8273', channel: 'web',
));
check('rejected when wallet cannot cover', ! $o->successful && $o->reasonCode === 'insufficient_balance',
    $o->reasonCode . ': ' . $o->message);
$bal = (float) Reseller::find($id)->bank_balance;
check('balance untouched on rejection', abs($bal - 10.0) < 0.001, (string) $bal);

// ------------------------------------------------------ refund path
section('Cancel and refund');
DB::table('reseller')->where('id', $id)->update(['balance' => 5000, 'bank_balance' => 5000, 'drive_bal' => 5000]);
$o = $recharge->place(new RechargeInput(
    reseller: Reseller::find($id), service: ServiceType::Flexiload,
    number: '01755555555', amount: 200, pin: '8273', channel: 'web',
));
check('request for refund test accepted', $o->successful, (string) $o->reasonCode);

if ($o->successful) {
    $req = $o->request->fresh();
    $col = $req->result === 'bank' ? 'bank_balance' : 'balance';
    $afterDebit = (float) Reseller::find($id)->{$col};

    $first = $recharge->cancelAndRefund($req, null, 'test');
    $afterRefund = (float) Reseller::find($id)->{$col};

    check('refund applied', $first === true);
    check('balance restored by exactly the cost',
        abs(($afterRefund - $afterDebit) - (float) $req->cost) < 0.001,
        sprintf('%.2f -> %.2f, cost %.2f', $afterDebit, $afterRefund, (float) $req->cost));
    check('status now Cancelled', $req->fresh()->status === TransactionStatus::Cancelled,
        $req->fresh()->status->label());

    // The important property: a second cancel must not pay out again.
    $second = $recharge->cancelAndRefund($req->fresh(), null, 'test repeat');
    $afterSecond = (float) Reseller::find($id)->{$col};
    check('second cancel is a no-op', $second === false);
    check('no double refund', abs($afterSecond - $afterRefund) < 0.001,
        sprintf('%.2f vs %.2f', $afterSecond, $afterRefund));
}

// ------------------------------------------------------ reconciliation
section('Books reconcile');
$ledgerNet = app(App\Services\Wallet\LedgerService::class)->replayBalance($id);
$requests = RechargeRequest::where('userid', $id)->get();
$debits = (float) LedgerEntry::where('userid', $id)->sum('debit');
$credits = (float) LedgerEntry::where('userid', $id)->sum('credit');
printf("  ledger: debits=%.2f credits=%.2f net=%.2f over %d request(s)\n",
    $debits, $credits, $ledgerNet, $requests->count());
$expectedDebits = (float) $requests->sum(fn ($r) => (float) $r->cost);
check('ledger debits equal the sum of request costs',
    abs($debits - $expectedDebits) < 0.001, sprintf('%.2f vs %.2f', $debits, $expectedDebits));

// ---------------------------------------------------------- teardown
section('Teardown');
$sids = RechargeRequest::where('userid', $id)->pluck('sid')->all();
DB::table('response')->whereIn('id_order', RechargeRequest::where('userid', $id)->pluck('id')->all())->delete();
DB::table('trans')->where('userid', $id)->delete();
DB::table('sendflexi')->where('userid', $id)->delete();
DB::table('activity_log')->where('userid', (string) $id)->delete();
DB::table('reseller')->where('id', $id)->delete();
check('test data removed', Reseller::find($id) === null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
