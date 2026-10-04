#!/usr/bin/env bash
# Prove that WalletService prevents the double-spend the legacy code allowed.
#
# Seeds a disposable reseller with a known balance, fires N concurrent debits at
# it, and checks how much was actually spent. Run once against the legacy
# read-compute-write pattern and once against WalletService.
set -u

LAR_ROOT="${LAR_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
SP="$(cd "$(dirname "$0")" && pwd)"
MYSQL="${MYSQL:-mysql}"
DB=telecomappdatabase

WORKERS=10
DEBIT=20
START_BALANCE=100
# 100 / 20 = at most 5 debits may succeed.
EXPECT_MAX_OK=$(( START_BALANCE / DEBIT ))

export LAR_ROOT

seed() {
  $MYSQL -u root "$DB" -N -e "
    DELETE FROM trans WHERE type='test' AND userid IN (SELECT id FROM reseller WHERE username='__conctest__');
    DELETE FROM reseller WHERE username='__conctest__';
    INSERT INTO reseller (username, password, pincode, type, name, mobile, email, note,
                          custype, p_id, admin_id, lft, rgt, bank_balance, balance,
                          balance_limit, create_date, ip, enbale_otp, tempor_otp,
                          last_login, login_count, tarif, domain_name, status,
                          login_allow, device_safe, time, \`delete\`, chnage_pin_d,
                          chnage_pin_t, chnage_pass_d, chnage_pass_t, de_pin, de_pass,
                          gotp_key, otp_choice, otp, webAccess, appsAccess)
    VALUES ('__conctest__','x','x','1','Concurrency Test','0','','','reseller1',1,1,0,0,
            0,$START_BALANCE,0,NOW(),'127.0.0.1',0,0,'','0','1','',1,1,0,0,0,
            '','','','','','','',0,'',1,1);
    SELECT LAST_INSERT_ID();"
}

run_round() {
  local mode="$1"
  local rid="$2"

  # Give every worker the same start instant so they truly contend.
  local start_at
  start_at=$(php -r 'echo microtime(true) + 1.5;')

  local pids=()
  local outdir="$SP/out-$mode"
  rm -rf "$outdir"; mkdir -p "$outdir"

  for i in $(seq 1 $WORKERS); do
    START_AT="$start_at" php "$SP/manual-concurrency-worker.php" "$rid" "$DEBIT" "$mode" \
      > "$outdir/$i.txt" 2>&1 &
    pids+=($!)
  done

  for p in "${pids[@]}"; do wait "$p"; done

  local ok declined errors final ledger
  ok=$(grep -lc '^OK' "$outdir"/*.txt 2>/dev/null | wc -l)
  declined=$(grep -l '^DECLINED' "$outdir"/*.txt 2>/dev/null | wc -l)
  errors=$(grep -l '^ERROR' "$outdir"/*.txt 2>/dev/null | wc -l)

  final=$($MYSQL -u root "$DB" -N -e "SELECT balance FROM reseller WHERE id=$rid;")
  ledger=$($MYSQL -u root "$DB" -N -e "SELECT COALESCE(SUM(debit),0) FROM trans WHERE userid=$rid AND type='test';")

  local spent
  spent=$(php -r "echo $START_BALANCE - $final;")

  printf '%-8s accepted=%-3s declined=%-3s errors=%-3s final_balance=%-8s spent=%-8s ledger_debits=%-8s\n' \
    "$mode" "$ok" "$declined" "$errors" "$final" "$spent" "$ledger"

  if [ "$errors" != "0" ]; then
    echo "   first error: $(grep -h '^ERROR' "$outdir"/*.txt | head -1)"
  fi

  # Report the verdict for this round.
  php -r '
    $ok = (int)$argv[1]; $max = (int)$argv[2]; $spent = (float)$argv[3];
    $debit = (float)$argv[4]; $ledger = (float)$argv[5]; $mode = $argv[6];
    $overspent = $ok > $max;
    $mismatch  = abs($spent - $ok * $debit) > 0.001;
    $ledgerBad = abs($ledger - $spent) > 0.001;
    if ($mode === "legacy") {
      echo ($overspent || $mismatch)
        ? "   -> legacy pattern OVERSPENT (this is the bug being fixed)\n"
        : "   -> legacy pattern happened not to collide this run\n";
    } else {
      $fail = $overspent || $mismatch || $ledgerBad;
      echo $fail
        ? "   -> FAIL: overspent=".var_export($overspent,true).
          " balance/accepted mismatch=".var_export($mismatch,true).
          " ledger mismatch=".var_export($ledgerBad,true)."\n"
        : "   -> PASS: at most $max accepted, balance and ledger agree exactly\n";
    }
  ' "$ok" "$EXPECT_MAX_OK" "$spent" "$DEBIT" "$ledger" "$mode"
}

echo "Concurrency test: $WORKERS simultaneous debits of $DEBIT from a balance of $START_BALANCE"
echo "At most $EXPECT_MAX_OK should be accepted."
echo

RID=$(seed)
run_round legacy "$RID"
echo

RID=$(seed)
run_round safe "$RID"
echo

# Clean up the disposable account and its ledger rows.
$MYSQL -u root "$DB" -e "
  DELETE FROM trans WHERE type='test' AND userid IN (SELECT id FROM reseller WHERE username='__conctest__');
  DELETE FROM reseller WHERE username='__conctest__';"
rm -rf "$SP"/out-legacy "$SP"/out-safe
echo "cleaned up test account"
