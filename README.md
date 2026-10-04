# Telecom Panel (Laravel 12)

A rewrite of the CodeIgniter 3 reseller/recharge platform in Laravel 12, running
against **the same MySQL database** so existing data, the legacy application and
the installed Android app all keep working during the transition.

---

## What this is

A mobile top-up and mobile-financial-services distribution platform for
Bangladesh: a head office sells airtime, internet packs, bKash/Nagad/Rocket
cash-in, bill payments and bank transfers through a five-tier reseller network.

| | |
|---|---|
| Framework | Laravel 12 (PHP 8.2+) |
| Database | MySQL / MariaDB — the existing `telecomappdatabase` schema |
| Front end | Blade + Tailwind 4 + Alpine 3, built with Vite |
| Panels | `/admin` (head office), `/` (resellers), `/apiapp` (Android app) |

---

## Quick start

```bash
cp .env.example .env          # then set DB_* to your existing database
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate           # see "Migrations" below — read it first
php artisan telecom:admin     # create your first administrator
php artisan serve
```

Sign in at `/admin/login`.

---

## Migrations — read before running

The migrations **modify the existing schema**. Back up first:

```bash
php artisan telecom:backup
```

They do four things, in order:

1. **`repair_zero_dates`** — rewrites `0000-00-00` values in
   `sendflexi.submitted_date` using the real time from `actual_send_time`.
   MyISAM accepted the zero date; InnoDB under strict mode rejects it.

2. **`convert_money_tables_to_innodb`** — converts every table to InnoDB.
   This is the most important change in the whole project. MyISAM has no
   transactions and no row locking, so the old balance code could be raced:
   two simultaneous requests both read the same opening balance and the second
   write discarded the first debit. See *Verifying it* below for a reproduction.

3. **`add_performance_and_integrity_indexes`** — adds the indexes the schema
   never had. `reseller` had an index on `id` only, so **every login was a full
   table scan** of a table whose AUTO_INCREMENT is past 213 000. Also adds a
   UNIQUE key on `reseller.username`, falling back to a plain index and warning
   you if duplicates already exist.

4. **`add_column_defaults_for_strict_mode`** — gives ~200 NOT NULL columns the
   default the server was silently substituting anyway, so strict mode can stay
   on. Strict mode is what makes an out-of-range amount fail loudly instead of
   being quietly coerced.

None of these change data semantics; the legacy app keeps working against the
same tables afterwards.

---

## How a recharge works

```
RechargeController / AppRechargeController
        │
        ▼
RechargeService::place()
        │
        ├─ RechargeValidator      account, amount, number, operator, blocks,
        │                         duplicate window, daily limit
        ├─ PinService             PIN, rate-limited and constant-time
        ├─ PricingService         rate / commission / charge  → PriceQuote
        ├─ RoutingService         which provider, and may it auto-dispatch
        │
        ▼  ─── one transaction, reseller row locked FOR UPDATE ───
        │   WalletService::debit()      balance + ledger line, atomically
        │   RechargeRequest::create()   the sendflexi row
        └─── commit ───
                │
                ▼  (queued, after commit — never inside the transaction)
        DispatchRechargeRequest → ProviderManager → EcareGateway
                                                  → GenericApiGateway
```

Two ordering rules carry most of the safety:

- **The balance check, the debit and the request row are one locked
  transaction.** The old code did those as three unlocked statements.
- **The provider HTTP call happens after commit, from a queue.** The old code
  called cURL with no timeout while a transaction was open, so one slow
  provider could exhaust the connection pool.

### Transaction statuses

| Value | Meaning |
|---|---|
| 0 | Pending — queued for automatic dispatch |
| 1 | Success |
| 2 | Partial |
| 3 | Cancelled (refunded) |
| 4 | Processing — handed to a provider |
| 5 | Waiting — held for an operator |

Values are the legacy ones and must not change: reports, the Android app and
provider callbacks all compare against them.

---

## Security

What was wrong, and what replaced it:

| Area | Before | Now |
|---|---|---|
| Concurrency | No locking; balance could be spent twice | `SELECT … FOR UPDATE` in one transaction |
| Passwords | 20 000-round SHA-1 chain, `mt_rand` salt | bcrypt cost 12, upgraded transparently on login |
| PIN | `pincode=api` / `ets` / `offline` skipped the check entirely | No bypass; attempts limited; constant-time compare |
| Login | No rate limit at all (90 000 rows in `login_attempts`) | Per-username and per-IP limits with lockout |
| App tokens | `sha1(uniqid(rand()))`, device id often ignored | 32 random bytes, bound to the device, expiry enforced once |
| Authorisation | Each controller checked (or forgot to check) the session | Guard middleware on the whole route group |
| Reseller scoping | Any sub-admin could edit any account by URL | `PermissionService::canManage()` — must outrank *and* own |
| Pricing | A missing price row evaluated to zero → free top-ups | Unpriced requests are refused |
| Routing | `foreach` kept the **last** row, ignoring `ORDER BY priority` | One route, ordering respected |
| SQL | Values interpolated into query strings | Eloquent / bound parameters throughout |
| Provider calls | Credentials in the query string, no timeout | POST body, explicit timeouts |
| Headers | None | CSP with per-request nonce, HSTS, nosniff, frame-deny |
| Audit | Attempted passwords stored in clear text | Never stored; context redacted before logging |

### Legacy password migration

Old hashes look like `digest:iterations:algorithm:salt`. `LegacyHasher` verifies
them, and `LegacyUserProvider` rewrites the hash as bcrypt on the first
successful sign-in. No password reset is needed and there is no downtime; the
weak digest stops protecting an account the moment its owner next logs in.

`tests/Unit/LegacyHasherTest.php` pins this against a verbatim copy of the
original algorithm.

---

## Settings

Everything in **Settings** carries a plain-language label and an explanation of
what changing it does. Two legacy columns are badly named and worth knowing
about, because they are flags rather than what their names suggest:

- `company.zip` — "force manual review for package top-ups"
- `company.city` — "force manual review for internet packs"
- `security_option.bkash_auto` — the auto-dispatch ceiling for **all** manual
  routes, not just bKash

Exposed through honest accessors on the models; new code should use those.

Hard caps in `config/telecom.php` apply on top of the database values, so a
mistaken settings change cannot remove a limit without a code deploy. The
shipped data has a daily limit of 5 000 000, which is effectively unlimited —
worth lowering.

---

## Commands

```bash
php artisan telecom:admin [username]     # create an administrator
php artisan telecom:admin name --reset   # reset a password and PIN
php artisan telecom:backup               # timestamped dump, keeps the last 14
php artisan telecom:repair-hierarchy --dry-run
php artisan telecom:repair-hierarchy     # rebuild cached ancestor columns
```

`telecom:admin` prefers `TELECOM_ADMIN_PASSWORD` / `TELECOM_ADMIN_PIN` from the
environment over `--password` / `--pin`, which would be visible in shell history.

### The hierarchy columns

`reseller.level1..level5` cache the chain of parents so commission reports can
group without a recursive query. **They are stored backwards:** `level5` is the
immediate parent, `level1` the most distant ancestor. `sendflexi` uses the
*opposite* order for its own level columns, so the two must never be copied
across positionally. Always read through `Reseller::ancestorIds()`.

The legacy code wrote these at each call site rather than in one place, so rows
drifted. Run `telecom:repair-hierarchy --dry-run` to see which accounts are
affected before writing.

---

## Queue

Provider dispatch runs on a queue. In production:

```bash
php artisan queue:work --queue=default --tries=3 --timeout=120
```

Use a supervisor, or on shared hosting a cron entry:

```
* * * * * cd /path/to/app && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

Without a worker, requests stay at status 0 (Pending) and are never sent.

---

## The mobile API

`/apiapp/*` keeps the exact paths, parameter names and response shapes the
installed Android client expects, so an existing APK works unchanged:

- sign-in posts to `/apiapp` itself; `/apiapp/loginchk` only asks whether a
  session is still alive
- every authenticated call sends `token` + `deviceid`
- responses carry `success` and `status`; `status = 2` sends the client back to
  the login screen

The app path reuses the same services as the web panel, so it cannot take a
shortcut around a limit, a PIN or the balance lock — which is how the legacy
copy of the flow ended up with its own, weaker rules.

---

## Verifying it

```bash
php artisan test                                   # 26 tests
php tests/manual-verify-recharge.php               # 56 engine checks
bash tests/manual-concurrency-test.sh              # the double-spend proof
```

The concurrency script seeds a disposable account with a balance of 100, fires
10 simultaneous debits of 20 at it and reports what happened. Typical output:

```
legacy   accepted=10  declined=0  final_balance=40.00   spent=60   ledger_debits=0.00
   -> legacy pattern OVERSPENT (this is the bug being fixed)
safe     accepted=5   declined=5  final_balance=0.00    spent=100  ledger_debits=100.00
   -> PASS: at most 5 accepted, balance and ledger agree exactly
```

The legacy pattern delivered 200 worth of recharges for 60.

Set `LAR_ROOT` and `MYSQL` if they are not on your `PATH`:

```bash
LAR_ROOT="$(pwd)" MYSQL=/path/to/mysql bash tests/manual-concurrency-test.sh
```

**Note:** the test suite runs against the configured MySQL database, not an
in-memory sqlite one, because the legacy schema cannot be rebuilt from
migrations. Every test is read-only or cleans up after itself. Point
`DB_DATABASE` at a copy before adding tests that write.

---

## Deployment (cPanel / shared hosting)

1. Upload the project **outside** `public_html`.
2. Point the document root at `laravel-app/public`, or copy its contents into
   `public_html` and set `$app->usePublicPath()` accordingly.
3. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `DB_*`.
4. `php artisan config:cache route:cache view:cache`
5. `npm run build` locally and upload `public/build` — node is not needed on
   the server.
6. Set up the queue cron above.
7. Add a nightly `php artisan telecom:backup`.

`storage/` and `bootstrap/cache/` must be writable. Do not make `storage/`
web-reachable — backups are written there.

---

## Still to do

- Bulk flexiload upload screen (`bulkflexi` / `phonebook` models exist)
- Scratch-card inventory screens (`ScratchCard` model exists)
- Admin-side ticket replies (the reseller side is complete)
- SMS gateway sending (`SmsServer` model exists; no driver yet)
- Online payment gateways (bKash/SSLCommerz) — manual deposit flow works today
- Modem/SIM management screens for on-premise dispatch
