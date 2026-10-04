<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;

/**
 * Writes ledger lines. Called only from WalletService, inside the transaction
 * that already holds the reseller row lock, so a balance change and its
 * statement line are committed together or not at all.
 *
 * The legacy `trans` table stores dates as strings in two different formats
 * (`date` as `Y-m-d`, `time` as a long human-readable string). Both are kept so
 * existing reports continue to work, and both are written from one Carbon
 * instance so they can never disagree.
 */
class LedgerService
{
    /**
     * @param  float  $signedAmount  Negative for a debit, positive for a credit.
     * @param  array  $meta          Optional: type, service, flex_id, send_sl, status.
     */
    public function record(
        int $resellerId,
        int $parentId,
        string $description,
        float $opening,
        float $closing,
        float $signedAmount,
        array $meta = [],
    ): LedgerEntry {
        $now = Carbon::now();

        return LedgerEntry::create([
            'userid'         => $resellerId,
            'desc'           => mb_substr($description, 0, 255),
            'oldbal'         => $opening,
            'debit'          => $signedAmount < 0 ? abs($signedAmount) : 0,
            'credit'         => $signedAmount > 0 ? $signedAmount : 0,
            'accountbalance' => $closing,
            'type'           => $meta['type'] ?? ($signedAmount < 0 ? 'minus' : 'plus'),
            'service'        => (int) ($meta['service'] ?? 0),
            'flex_id'        => (string) ($meta['flex_id'] ?? ''),
            'status'         => (int) ($meta['status'] ?? 0),
            'send_sl'        => (int) ($meta['send_sl'] ?? 0),
            'p_id'           => $parentId,
            'date'           => $now->format('Y-m-d'),
            'time'           => $now->format('j F Y g:i A'),
        ]);
    }

    /**
     * Recompute a wallet balance by replaying the ledger, for reconciliation.
     *
     * This is the audit counterpart to the stored balance: if the two disagree,
     * either a movement bypassed WalletService or a row was edited by hand.
     */
    public function replayBalance(int $resellerId): float
    {
        $totals = LedgerEntry::query()
            ->where('userid', $resellerId)
            ->selectRaw('COALESCE(SUM(credit), 0) AS credits, COALESCE(SUM(debit), 0) AS debits')
            ->first();

        return round((float) $totals->credits - (float) $totals->debits, 2);
    }

    /** Most recent ledger line for an account, or null if it has no history. */
    public function latest(int $resellerId): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->where('userid', $resellerId)
            ->orderByDesc('id')
            ->first();
    }
}
