<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\TransactionStatus;
use App\Models\ApiProvider;
use App\Models\LedgerEntry;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the dashboards.
 *
 * Every figure is produced by a single grouped query. The legacy dashboard
 * looped over resellers in PHP and ran a handful of queries per row - on a
 * network of any size that was thousands of queries to render one page, which
 * is why it was slow enough that operators avoided it. Short-lived caching on
 * top keeps repeat loads cheap without making the numbers feel stale.
 */
class DashboardMetrics
{
    private const CACHE_TTL = 60;

    /** Headline figures for the admin dashboard. */
    public function adminSummary(): array
    {
        return Cache::remember('metrics.admin.summary', self::CACHE_TTL, function (): array {
            $today = Carbon::now()->format('Y-m-d');
            $yesterday = Carbon::yesterday()->format('Y-m-d');
            $monthStart = Carbon::now()->startOfMonth()->format('Y-m-d');

            $todayRow = $this->totalsFor($today, $today);
            $yesterdayRow = $this->totalsFor($yesterday, $yesterday);
            $monthRow = $this->totalsFor($monthStart, $today);

            $queue = RechargeRequest::query()
                ->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(balance), 0) AS amount')
                ->whereIn('status', [
                    TransactionStatus::Waiting->value,
                    TransactionStatus::Pending->value,
                    TransactionStatus::Processing->value,
                ])
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            return [
                'today' => $todayRow,
                'yesterday' => $yesterdayRow,
                'month' => $monthRow,
                'change' => [
                    'volume' => $this->percentChange(
                        (float) $yesterdayRow['volume'],
                        (float) $todayRow['volume'],
                    ),
                    'count' => $this->percentChange(
                        (float) $yesterdayRow['count'],
                        (float) $todayRow['count'],
                    ),
                ],
                'queue' => [
                    'waiting' => (int) ($queue[TransactionStatus::Waiting->value]->n ?? 0),
                    'pending' => (int) ($queue[TransactionStatus::Pending->value]->n ?? 0),
                    'processing' => (int) ($queue[TransactionStatus::Processing->value]->n ?? 0),
                    'waiting_amount' => (float) ($queue[TransactionStatus::Waiting->value]->amount ?? 0),
                ],
                'network' => $this->networkCounts(),
                'float' => $this->floatTotals(),
            ];
        });
    }

    /**
     * Volume and success rate per day over a window, for the trend chart.
     *
     * @return Collection<int, array>
     */
    public function dailySeries(int $days = 14): Collection
    {
        return Cache::remember("metrics.series.{$days}", self::CACHE_TTL, function () use ($days) {
            $from = Carbon::now()->subDays($days - 1)->format('Y-m-d');

            $rows = RechargeRequest::query()
                ->selectRaw('idate AS day')
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(balance), 0) AS volume')
                ->selectRaw('COALESCE(SUM(cost), 0) AS charged')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful', [TransactionStatus::Success->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [TransactionStatus::Cancelled->value])
                ->where('idate', '>=', $from)
                ->groupBy('idate')
                ->orderBy('idate')
                ->get()
                ->keyBy('day');

            // Fill gaps so the chart has a point for every day, not just the
            // days that happened to have traffic.
            return collect(range($days - 1, 0))->map(function (int $offset) use ($rows) {
                $date = Carbon::now()->subDays($offset);
                $key = $date->format('Y-m-d');
                $row = $rows[$key] ?? null;

                return [
                    'day' => $key,
                    'label' => $date->format('d M'),
                    'total' => (int) ($row->total ?? 0),
                    'volume' => (float) ($row->volume ?? 0),
                    'charged' => (float) ($row->charged ?? 0),
                    'successful' => (int) ($row->successful ?? 0),
                    'cancelled' => (int) ($row->cancelled ?? 0),
                ];
            });
        });
    }

    /** Volume split by service, for the current month. */
    public function serviceBreakdown(): Collection
    {
        return Cache::remember('metrics.services', self::CACHE_TTL, function () {
            $from = Carbon::now()->startOfMonth()->format('Y-m-d');

            return RechargeRequest::query()
                ->selectRaw('service, COUNT(*) AS total, COALESCE(SUM(balance), 0) AS volume')
                ->where('idate', '>=', $from)
                ->groupBy('service')
                ->orderByDesc('volume')
                ->get()
                ->map(fn ($row) => [
                    'service' => (int) $row->service,
                    'label' => \App\Enums\ServiceType::tryFromLoose($row->service)?->label()
                        ?? (string) \App\Models\Service::where('serviceid', $row->service)->value('title')
                        ?? 'Other',
                    'total' => (int) $row->total,
                    'volume' => (float) $row->volume,
                ]);
        });
    }

    /** Volume split by operator, for the current month. */
    public function operatorBreakdown(): Collection
    {
        return Cache::remember('metrics.operators', self::CACHE_TTL, function () {
            $from = Carbon::now()->startOfMonth()->format('Y-m-d');

            return RechargeRequest::query()
                ->selectRaw('pcode, COUNT(*) AS total, COALESCE(SUM(balance), 0) AS volume')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful', [TransactionStatus::Success->value])
                ->where('idate', '>=', $from)
                ->whereNotNull('pcode')
                ->where('pcode', '!=', '')
                ->groupBy('pcode')
                ->orderByDesc('volume')
                ->get()
                ->map(fn ($row) => [
                    'code' => (string) $row->pcode,
                    'total' => (int) $row->total,
                    'volume' => (float) $row->volume,
                    'success_rate' => (int) $row->total > 0
                        ? round((int) $row->successful / (int) $row->total * 100, 1)
                        : 0.0,
                ]);
        });
    }

    /** Resellers with the highest volume this month. */
    public function topResellers(int $limit = 8): Collection
    {
        return Cache::remember("metrics.top-resellers.{$limit}", self::CACHE_TTL, function () use ($limit) {
            $from = Carbon::now()->startOfMonth()->format('Y-m-d');

            $rows = RechargeRequest::query()
                ->selectRaw('userid, COUNT(*) AS total, COALESCE(SUM(balance), 0) AS volume')
                ->where('idate', '>=', $from)
                ->groupBy('userid')
                ->orderByDesc('volume')
                ->limit($limit)
                ->get();

            // One extra query for all the names, rather than one per row.
            $names = Reseller::query()
                ->whereIn('id', $rows->pluck('userid')->all())
                ->get(['id', 'username', 'name', 'custype'])
                ->keyBy('id');

            return $rows->map(fn ($row) => [
                'id' => (int) $row->userid,
                'name' => $names[$row->userid]?->displayName() ?? 'Deleted account',
                'level' => $names[$row->userid]?->level()?->defaultLabel() ?? '-',
                'total' => (int) $row->total,
                'volume' => (float) $row->volume,
            ]);
        });
    }

    /** Provider balances, so a provider running dry is visible. */
    public function providerBalances(): Collection
    {
        return ApiProvider::query()
            ->where('status', 1)
            ->get(['id', 'api_title', 'balance', 'provider'])
            ->map(fn (ApiProvider $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->api_title,
                'balance' => (float) $p->balance,
                'low' => (float) $p->balance < 1000,
            ]);
    }

    /** Figures for one reseller's own dashboard. */
    public function resellerSummary(Reseller $reseller): array
    {
        $today = Carbon::now()->format('Y-m-d');
        $monthStart = Carbon::now()->startOfMonth()->format('Y-m-d');

        $scope = fn () => RechargeRequest::query()->where('userid', $reseller->id);

        $todayRow = (clone $scope())
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(balance), 0) AS volume, COALESCE(SUM(cost), 0) AS charged')
            ->where('idate', $today)
            ->first();

        $monthRow = (clone $scope())
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(balance), 0) AS volume, COALESCE(SUM(cost), 0) AS charged')
            ->where('idate', '>=', $monthStart)
            ->first();

        $statuses = (clone $scope())
            ->selectRaw('status, COUNT(*) AS n')
            ->where('idate', '>=', $monthStart)
            ->groupBy('status')
            ->pluck('n', 'status');

        $commission = (float) LedgerEntry::query()
            ->where('userid', $reseller->id)
            ->where('date', '>=', $monthStart)
            ->sum('credit');

        return [
            'today' => [
                'count' => (int) ($todayRow->count ?? 0),
                'volume' => (float) ($todayRow->volume ?? 0),
                'charged' => (float) ($todayRow->charged ?? 0),
            ],
            'month' => [
                'count' => (int) ($monthRow->count ?? 0),
                'volume' => (float) ($monthRow->volume ?? 0),
                'charged' => (float) ($monthRow->charged ?? 0),
                // What the reseller kept: face value delivered minus what they paid.
                'savings' => (float) ($monthRow->volume ?? 0) - (float) ($monthRow->charged ?? 0),
            ],
            'statuses' => [
                'success' => (int) ($statuses[TransactionStatus::Success->value] ?? 0),
                'waiting' => (int) ($statuses[TransactionStatus::Waiting->value] ?? 0),
                'pending' => (int) ($statuses[TransactionStatus::Pending->value] ?? 0),
                'cancelled' => (int) ($statuses[TransactionStatus::Cancelled->value] ?? 0),
            ],
            'commission' => $commission,
            'downline' => Reseller::query()->where('p_id', $reseller->id)->where('delete', '!=', 1)->count(),
        ];
    }

    // ------------------------------------------------------------ internals

    private function totalsFor(string $from, string $to): array
    {
        $row = RechargeRequest::query()
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(balance), 0) AS volume')
            ->selectRaw('COALESCE(SUM(cost), 0) AS charged')
            ->selectRaw('COALESCE(SUM(parent_cost), 0) AS parent_cost')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successful', [TransactionStatus::Success->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [TransactionStatus::Cancelled->value])
            ->whereBetween('idate', [$from, $to])
            ->first();

        $count = (int) ($row->count ?? 0);
        $charged = (float) ($row->charged ?? 0);
        $volume = (float) ($row->volume ?? 0);

        return [
            'count' => $count,
            'volume' => $volume,
            'charged' => $charged,
            // Margin the platform retained on the traffic.
            'margin' => round($charged - $volume, 2),
            'successful' => (int) ($row->successful ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'success_rate' => $count > 0 ? round((int) ($row->successful ?? 0) / $count * 100, 1) : 0.0,
        ];
    }

    private function networkCounts(): array
    {
        $byLevel = Reseller::query()
            ->selectRaw('custype, COUNT(*) AS n')
            ->where('delete', '!=', 1)
            ->groupBy('custype')
            ->pluck('n', 'custype');

        return [
            'total' => (int) $byLevel->sum(),
            'active' => (int) Reseller::query()->active()->count(),
            'by_level' => $byLevel->all(),
        ];
    }

    /** Total balance held across the network, by wallet. */
    private function floatTotals(): array
    {
        $row = DB::table('reseller')
            ->selectRaw('COALESCE(SUM(balance), 0) AS main')
            ->selectRaw('COALESCE(SUM(bank_balance), 0) AS bank')
            ->selectRaw('COALESCE(SUM(drive_bal), 0) AS drive')
            ->where('delete', '!=', 1)
            ->first();

        return [
            'main' => (float) ($row->main ?? 0),
            'bank' => (float) ($row->bank ?? 0),
            'drive' => (float) ($row->drive ?? 0),
        ];
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0.0) {
            // No baseline to compare against; the UI shows a dash rather than
            // a meaningless "+100%".
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
