<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\RechargeRequest;
use App\Services\Reporting\DashboardMetrics;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly SettingsService $settings,
    ) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'summary'   => $this->metrics->adminSummary(),
            'series'    => $this->metrics->dailySeries(14),
            'services'  => $this->metrics->serviceBreakdown(),
            'operators' => $this->metrics->operatorBreakdown(),
            'top'       => $this->metrics->topResellers(),
            'providers' => $this->metrics->providerBalances(),
            'symbol'    => $this->settings->currencySymbol(),

            // The most recent activity, eager-loading the reseller so the table
            // does not issue a query per row.
            'recent' => RechargeRequest::query()
                ->with('reseller:id,username,name,custype')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /** Chart data, so the dashboard can refresh without a full page load. */
    public function series(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 14);
        $days = max(7, min(90, $days));

        return response()->json([
            'series' => $this->metrics->dailySeries($days),
        ]);
    }
}
