<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionStatus;
use App\Models\RechargeRequest;
use App\Services\Reporting\DashboardMetrics;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporting.
 *
 * Exports stream row by row rather than building an array first, so a wide
 * date range cannot exhaust memory. The legacy export loaded everything and
 * then echoed it, which failed silently on large ranges and produced a
 * truncated file.
 */
class ReportController
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.reports.index', [
            'summary' => $this->metrics->adminSummary(),
            'series'  => $this->metrics->dailySeries(30),
            'symbol'  => $this->settings->currencySymbol(),
        ]);
    }

    public function operators(Request $request): View
    {
        return view('admin.reports.operators', [
            'operators' => $this->metrics->operatorBreakdown(),
            'services'  => $this->metrics->serviceBreakdown(),
            'symbol'    => $this->settings->currencySymbol(),
        ]);
    }

    public function daily(Request $request): View
    {
        $days = max(7, min(90, (int) $request->integer('days', 30)));

        return view('admin.reports.daily', [
            'series' => $this->metrics->dailySeries($days),
            'days'   => $days,
            'symbol' => $this->settings->currencySymbol(),
        ]);
    }

    public function profit(Request $request): View
    {
        $from = $request->date('from')?->format('Y-m-d') ?? now()->startOfMonth()->format('Y-m-d');
        $to = $request->date('to')?->format('Y-m-d') ?? now()->format('Y-m-d');

        $rows = RechargeRequest::query()
            ->selectRaw('service')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(balance), 0) AS volume')
            ->selectRaw('COALESCE(SUM(cost), 0) AS charged')
            ->selectRaw('COALESCE(SUM(parent_cost), 0) AS parent_cost')
            ->whereBetween('idate', [$from, $to])
            ->where('status', '!=', TransactionStatus::Cancelled->value)
            ->groupBy('service')
            ->get()
            ->map(fn ($row) => [
                'label'   => \App\Enums\ServiceType::tryFromLoose($row->service)?->label() ?? 'Other',
                'total'   => (int) $row->total,
                'volume'  => (float) $row->volume,
                'charged' => (float) $row->charged,
                'margin'  => round((float) $row->charged - (float) $row->volume, 2),
            ]);

        return view('admin.reports.profit', [
            'rows'   => $rows,
            'from'   => $from,
            'to'     => $to,
            'symbol' => $this->settings->currencySymbol(),
        ]);
    }

    /** Stream the filtered transaction set as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $from = $request->date('from')?->format('Y-m-d') ?? now()->startOfMonth()->format('Y-m-d');
        $to = $request->date('to')?->format('Y-m-d') ?? now()->format('Y-m-d');

        $query = RechargeRequest::query()
            ->with('reseller:id,username')
            ->whereBetween('idate', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->when($request->filled('service'), fn ($q) => $q->where('service', $request->integer('service')))
            ->orderBy('id');

        $filename = sprintf('transactions-%s-to-%s.csv', $from, $to);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'Reference', 'Date', 'Reseller', 'Service', 'Operator',
                'Number', 'Amount', 'Charged', 'Status', 'Provider Txn',
            ]);

            // chunkById keeps memory flat regardless of how many rows match.
            $query->chunkById(500, function ($chunk) use ($out): void {
                foreach ($chunk as $row) {
                    fputcsv($out, [
                        $row->sid,
                        $row->submitted_date?->format('Y-m-d H:i:s'),
                        $row->reseller?->username ?? '',
                        $row->serviceLabel(),
                        $row->pcode,
                        $row->phone,
                        number_format((float) $row->balance, 2, '.', ''),
                        number_format((float) $row->cost, 2, '.', ''),
                        $row->status->label(),
                        $row->trxid,
                    ]);
                }

                flush();
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
