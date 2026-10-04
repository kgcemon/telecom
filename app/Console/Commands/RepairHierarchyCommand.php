<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reseller\HierarchyService;
use Illuminate\Console\Command;

/**
 * Rebuilds the denormalised ancestor columns on every reseller.
 *
 * `reseller.level1..level5` cache the chain of parents so commission reports
 * can group without a recursive query. The legacy code wrote those columns at
 * each call site rather than in one place, so rows drifted out of step with
 * `p_id` - which silently mis-attributes commission. Run this once after the
 * migration, and again any time accounts have been moved by hand.
 */
class RepairHierarchyCommand extends Command
{
    protected $signature = 'telecom:repair-hierarchy {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild the cached reseller ancestor columns from p_id';

    public function handle(HierarchyService $hierarchy): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run: inspecting accounts without writing.');

            $mismatched = $this->countMismatched($hierarchy);

            $this->info($mismatched === 0
                ? 'Every account already has a correct ancestor chain.'
                : "{$mismatched} account(s) would be corrected.");

            return self::SUCCESS;
        }

        $this->info('Rebuilding ancestor chains...');

        $fixed = $hierarchy->repairAll();

        $this->info($fixed === 0
            ? 'Every account was already correct; nothing changed.'
            : "Corrected {$fixed} account(s).");

        return self::SUCCESS;
    }

    private function countMismatched(HierarchyService $hierarchy): int
    {
        $count = 0;

        \App\Models\Reseller::query()
            ->where('delete', '!=', 1)
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use ($hierarchy, &$count): void {
                foreach ($chunk as $reseller) {
                    $expected = $hierarchy->ancestorsOf($reseller);

                    $actual = array_map('intval', array_filter([
                        $reseller->level5, $reseller->level4, $reseller->level3,
                        $reseller->level2, $reseller->level1,
                    ], fn ($v) => $v !== null && $v !== '' && (int) $v > 0));

                    if ($expected !== $actual) {
                        $count++;
                        $this->line("  #{$reseller->id} {$reseller->username}: "
                            .'['.implode(',', $actual).'] -> ['.implode(',', $expected).']');
                    }
                }
            });

        return $count;
    }
}
