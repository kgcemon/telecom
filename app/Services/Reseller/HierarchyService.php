<?php

declare(strict_types=1);

namespace App\Services\Reseller;

use App\Models\Reseller;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the reseller tree.
 *
 * The chain of ancestors is denormalised onto each row (level1..level5) so
 * commission reports can group without a recursive query. That denormalisation
 * is only safe if something keeps it in step with `p_id`, which the legacy code
 * did ad hoc at each call site - with the result that several accounts carry
 * ancestors that no longer match their parent. This service is the one place
 * that writes those columns, and `repairChain()` fixes rows that drifted.
 */
class HierarchyService
{
    /** How many ancestor levels the schema stores. */
    private const DEPTH = 5;

    /**
     * Ancestor ids for a new child of `$parent`, nearest first.
     *
     * @return array<int, int>
     */
    public function ancestorChainFor(Reseller $parent): array
    {
        $chain = [(int) $parent->id];

        foreach ($parent->ancestorIds() as $id) {
            if (count($chain) >= self::DEPTH) {
                break;
            }

            $chain[] = (int) $id;
        }

        return $chain;
    }

    /** Walk up from an account, following `p_id` rather than the cached columns. */
    public function ancestorsOf(Reseller $reseller, int $limit = self::DEPTH): array
    {
        $chain = [];
        $currentId = (int) $reseller->p_id;
        $seen = [(int) $reseller->id];

        while ($currentId > 0 && count($chain) < $limit) {
            // A cycle would otherwise loop forever; the legacy code used a
            // fixed eight iterations to paper over exactly this.
            if (in_array($currentId, $seen, true)) {
                break;
            }

            $seen[] = $currentId;
            $chain[] = $currentId;

            $currentId = (int) (DB::table('reseller')->where('id', $currentId)->value('p_id') ?? 0);
        }

        return $chain;
    }

    /** All ids at or below an account, for scoping reports. */
    public function subtreeIds(Reseller $reseller): array
    {
        return Reseller::query()
            ->downlineOf((int) $reseller->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->push((int) $reseller->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Rewrite the cached ancestor columns for one account from `p_id`.
     *
     * Written in the legacy order: level5 is the immediate parent and level1
     * the most distant ancestor. Unused slots are cleared rather than left as
     * the 0 the old writer padded them with, so `ancestorIds()` does not have
     * to filter noise.
     */
    public function repairChain(Reseller $reseller): void
    {
        $chain = $this->ancestorsOf($reseller);

        $reseller->forceFill([
            'level5' => (string) ($chain[0] ?? ''),
            'level4' => (string) ($chain[1] ?? ''),
            'level3' => (string) ($chain[2] ?? ''),
            'level2' => (string) ($chain[3] ?? ''),
            'level1' => (string) ($chain[4] ?? ''),
        ])->save();
    }

    /**
     * Rebuild every cached chain. Exposed as `telecom:repair-hierarchy` so a
     * deployment can reconcile rows the legacy code left inconsistent.
     *
     * @return int number of rows corrected
     */
    public function repairAll(?callable $progress = null): int
    {
        $fixed = 0;

        Reseller::query()
            ->where('delete', '!=', 1)
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$fixed, $progress): void {
                foreach ($chunk as $reseller) {
                    $expected = $this->ancestorsOf($reseller);
                    $actual = array_map('intval', array_filter([
                        $reseller->level5, $reseller->level4, $reseller->level3,
                        $reseller->level2, $reseller->level1,
                    ], fn ($v) => $v !== null && $v !== '' && (int) $v > 0));

                    if ($expected !== $actual) {
                        $this->repairChain($reseller);
                        $fixed++;
                    }

                    if ($progress !== null) {
                        $progress($reseller);
                    }
                }
            });

        return $fixed;
    }

    /**
     * Move an account to a new parent, then refresh its own chain and those of
     * everything beneath it.
     */
    public function reparent(Reseller $reseller, Reseller $newParent): void
    {
        if ((int) $newParent->id === (int) $reseller->id) {
            throw new \InvalidArgumentException('An account cannot be its own parent.');
        }

        // Refuse a move that would create a cycle.
        if (in_array((int) $reseller->id, $this->ancestorsOf($newParent, 20), true)) {
            throw new \InvalidArgumentException('That move would create a loop in the hierarchy.');
        }

        DB::transaction(function () use ($reseller, $newParent): void {
            $reseller->forceFill([
                'p_id'     => (int) $newParent->id,
                'custype'  => $reseller->custype,
                'admin_id' => (string) ($newParent->admin_id ?: $newParent->id),
            ])->save();

            $this->repairChain($reseller->refresh());

            foreach ($this->subtreeIds($reseller) as $id) {
                if ($id === (int) $reseller->id) {
                    continue;
                }

                if ($descendant = Reseller::find($id)) {
                    $this->repairChain($descendant);
                }
            }
        });
    }
}
