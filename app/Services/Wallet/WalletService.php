<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Services\Wallet\Exceptions\InsufficientBalanceException;
use App\Services\Wallet\Exceptions\WalletException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a balance may change.
 *
 * Every movement happens inside a transaction that begins by taking a row lock
 * on the reseller (`SELECT ... FOR UPDATE`), so the read-modify-write of a
 * balance cannot interleave with a concurrent one. The legacy code did the same
 * three steps with no lock and no transaction, which let two simultaneous
 * requests both observe the same opening balance and spend it twice; it also
 * let a mid-request failure leave a debit with no ledger row. Both are closed
 * here, and both depend on the money tables being InnoDB - see the
 * convert_money_tables_to_innodb migration.
 *
 * Balances are computed in integer paisa (1/100 of a unit) rather than floats.
 * Repeated float arithmetic on values like 10.005 drifts, and the legacy code
 * compared float balances directly, so a reseller could end up a fraction short
 * or over. Converting at the boundary keeps the arithmetic exact.
 */
class WalletService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Debit a wallet and write the matching ledger line, atomically.
     *
     * @param  string  $description  Human-readable statement line.
     * @param  array   $meta         Extra ledger columns (service, flex_id, send_sl, type).
     *
     * @throws InsufficientBalanceException when the wallet cannot cover the amount.
     */
    public function debit(
        Reseller|int $reseller,
        WalletType $wallet,
        float $amount,
        string $description,
        array $meta = [],
    ): LedgerEntry {
        return $this->move($reseller, $wallet, -abs($amount), $description, $meta);
    }

    /**
     * Credit a wallet and write the matching ledger line, atomically.
     */
    public function credit(
        Reseller|int $reseller,
        WalletType $wallet,
        float $amount,
        string $description,
        array $meta = [],
    ): LedgerEntry {
        return $this->move($reseller, $wallet, abs($amount), $description, $meta);
    }

    /**
     * Move balance between two accounts as one atomic unit.
     *
     * Both rows are locked in ascending id order. Locking in a deterministic
     * order is what stops two opposite transfers (A to B and B to A) from each
     * holding one lock and waiting for the other, which would deadlock.
     */
    public function transfer(
        Reseller|int $from,
        Reseller|int $to,
        WalletType $wallet,
        float $amount,
        string $description,
        array $meta = [],
    ): array {
        $fromId = $this->idOf($from);
        $toId = $this->idOf($to);

        if ($fromId === $toId) {
            throw new WalletException('Cannot transfer balance to the same account.');
        }

        $amount = abs($amount);

        if ($amount <= 0) {
            throw new WalletException('Transfer amount must be greater than zero.');
        }

        return DB::transaction(function () use ($fromId, $toId, $wallet, $amount, $description, $meta) {
            // Deterministic lock order prevents deadlocks between mirrored transfers.
            $ids = [$fromId, $toId];
            sort($ids);
            $locked = $this->lockMany($ids);

            $sender = $locked[$fromId];
            $recipient = $locked[$toId];

            $debit = $this->applyLocked($sender, $wallet, -$amount, $description, $meta);
            $credit = $this->applyLocked($recipient, $wallet, $amount, $description, $meta);

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * Reverse a previous movement, e.g. refunding a cancelled recharge.
     *
     * Refunds are idempotent at the caller's level: RechargeService marks the
     * request refunded inside the same transaction, so a repeated cancel cannot
     * credit twice.
     */
    public function refund(
        Reseller|int $reseller,
        WalletType $wallet,
        float $amount,
        string $description,
        array $meta = [],
    ): LedgerEntry {
        return $this->credit($reseller, $wallet, $amount, $description, [
            'type' => 'refund',
        ] + $meta);
    }

    /**
     * Current balance, read under a lock. Use inside an enclosing transaction
     * when a decision will be made from the value.
     */
    public function lockedBalance(Reseller|int $reseller, WalletType $wallet): float
    {
        $row = $this->lock($this->idOf($reseller));

        return (float) $row->{$wallet->column()};
    }

    /** Spendable balance, honouring the credit floor on the main wallet. */
    public function spendable(Reseller $reseller, WalletType $wallet): float
    {
        return $reseller->spendableBalance($wallet);
    }

    /**
     * Run a callback with the reseller row locked for the whole duration.
     *
     * RechargeService uses this to hold the lock across validation, pricing,
     * the debit and the insert of the request row, so nothing can change the
     * balance between the check and the deduction.
     */
    public function withLock(Reseller|int $reseller, callable $callback): mixed
    {
        return DB::transaction(function () use ($reseller, $callback) {
            return $callback($this->lock($this->idOf($reseller)), $this);
        });
    }

    // ------------------------------------------------------------ internals

    private function move(
        Reseller|int $reseller,
        WalletType $wallet,
        float $signedAmount,
        string $description,
        array $meta,
    ): LedgerEntry {
        if ($signedAmount === 0.0) {
            throw new WalletException('Movement amount must be greater than zero.');
        }

        return DB::transaction(function () use ($reseller, $wallet, $signedAmount, $description, $meta) {
            $locked = $this->lock($this->idOf($reseller));

            return $this->applyLocked($locked, $wallet, $signedAmount, $description, $meta);
        });
    }

    /**
     * Apply a movement to an already-locked row.
     *
     * Must only be called from inside a transaction that holds the lock, which
     * is why it is private: the lock is the invariant that makes this safe.
     */
    private function applyLocked(
        object $locked,
        WalletType $wallet,
        float $signedAmount,
        string $description,
        array $meta,
    ): LedgerEntry {
        $column = $wallet->column();

        $openingPaisa = $this->toPaisa((float) $locked->{$column});
        $deltaPaisa = $this->toPaisa($signedAmount);
        $closingPaisa = $openingPaisa + $deltaPaisa;

        if ($deltaPaisa < 0) {
            // The main wallet may not be spent below its credit floor.
            $floorPaisa = $wallet->honoursCreditLimit()
                ? $this->toPaisa((float) ($locked->balance_limit ?? 0))
                : 0;

            if ($closingPaisa < $floorPaisa) {
                throw new InsufficientBalanceException(
                    resellerId: (int) $locked->id,
                    wallet: $wallet,
                    required: abs($signedAmount),
                    available: $this->toAmount($openingPaisa - $floorPaisa),
                );
            }
        }

        $opening = $this->toAmount($openingPaisa);
        $closing = $this->toAmount($closingPaisa);

        $affected = DB::table('reseller')
            ->where('id', $locked->id)
            ->update([$column => $closing]);

        if ($affected !== 1) {
            throw new WalletException(
                "Failed to update {$wallet->value} wallet for reseller {$locked->id}."
            );
        }

        return $this->ledger->record(
            resellerId: (int) $locked->id,
            parentId: (int) ($locked->p_id ?? 0),
            description: $description,
            opening: $opening,
            closing: $closing,
            signedAmount: $this->toAmount($deltaPaisa),
            meta: $meta,
        );
    }

    /** Lock one reseller row for update, returning the raw row. */
    private function lock(int $resellerId): object
    {
        $row = DB::table('reseller')
            ->where('id', $resellerId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new WalletException("Reseller {$resellerId} not found.");
        }

        return $row;
    }

    /**
     * Lock several reseller rows in one statement.
     *
     * @param  array<int>  $ids  Must already be sorted by the caller.
     * @return array<int,object> keyed by reseller id
     */
    private function lockMany(array $ids): array
    {
        $rows = DB::table('reseller')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new WalletException("Reseller {$id} not found.");
            }
        }

        return $rows;
    }

    private function idOf(Reseller|int $reseller): int
    {
        return $reseller instanceof Reseller ? (int) $reseller->id : $reseller;
    }

    /** Exact integer paisa, avoiding float accumulation error. */
    private function toPaisa(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function toAmount(int $paisa): float
    {
        return $paisa / 100;
    }
}
