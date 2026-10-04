<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Models\Notice;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Services\Reporting\DashboardMetrics;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->attributes->get('current_reseller');

        abort_unless($user instanceof Reseller, 403);

        return view('reseller.dashboard', [
            'user'    => $user,
            'summary' => $this->metrics->resellerSummary($user),
            'series'  => $this->ownSeries($user),
            'symbol'  => $this->settings->currencySymbol(),
            'security' => $this->settings->security(),

            'recent' => RechargeRequest::query()
                ->where('userid', $user->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),

            // Notices for everyone, or scoped to this account's level.
            'notices' => Notice::query()
                ->where('status', 1)
                ->where(fn ($q) => $q->whereIn('member', ['all', '', (string) $user->custype]))
                ->orderByDesc('id')
                ->limit(3)
                ->get(),
        ]);
    }

    /**
     * Fourteen days of this reseller's own volume, gap-filled so the chart
     * reads correctly on days with no traffic.
     */
    private function ownSeries(Reseller $user): \Illuminate\Support\Collection
    {
        $from = now()->subDays(13)->format('Y-m-d');

        $rows = RechargeRequest::query()
            ->selectRaw('idate AS day, COUNT(*) AS total, COALESCE(SUM(balance), 0) AS volume')
            ->where('userid', $user->id)
            ->where('idate', '>=', $from)
            ->groupBy('idate')
            ->get()
            ->keyBy('day');

        return collect(range(13, 0))->map(function (int $offset) use ($rows) {
            $date = now()->subDays($offset);
            $row = $rows[$date->format('Y-m-d')] ?? null;

            return [
                'label'  => $date->format('d M'),
                'total'  => (int) ($row->total ?? 0),
                'volume' => (float) ($row->volume ?? 0),
            ];
        });
    }
}
