<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Jobs\DispatchRechargeRequest;
use App\Models\RechargeRequest;
use App\Models\Service;
use App\Services\Recharge\RechargeService;
use App\Services\Security\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The approval queue and transaction browser.
 *
 * Two things differ from the legacy screens. Approvals are applied one row at
 * a time through RechargeService, which owns the status transition, so a bulk
 * action cannot half-apply. And every listing is paginated with a hard page
 * size: the legacy history page selected every matching row and rendered it,
 * so a date range covering a busy month exhausted memory and returned a blank
 * page.
 */
class RequestQueueController
{
    public function __construct(
        private readonly RechargeService $recharge,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Requests needing an operator decision. */
    public function pending(Request $request): View
    {
        $query = $this->filtered($request)
            ->whereIn('status', [
                TransactionStatus::Waiting->value,
                TransactionStatus::Pending->value,
                TransactionStatus::Processing->value,
            ]);

        return view('admin.requests.pending', [
            'requests' => $query->orderBy('submitted_date')->paginate($this->perPage())->withQueryString(),
            'services' => $this->serviceOptions(),
            'symbol'   => $this->settings->currencySymbol(),
            'filters'  => $this->currentFilters($request),
            'totals'   => $this->totals(clone $query),
        ]);
    }

    /** Full searchable history. */
    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.requests.index', [
            'requests' => $query->orderByDesc('id')->paginate($this->perPage())->withQueryString(),
            'services' => $this->serviceOptions(),
            'statuses' => TransactionStatus::cases(),
            'symbol'   => $this->settings->currencySymbol(),
            'filters'  => $this->currentFilters($request),
            'totals'   => $this->totals(clone $query),
        ]);
    }

    public function show(RechargeRequest $rechargeRequest): View
    {
        $rechargeRequest->load(['reseller:id,username,name,custype,mobile', 'providerResponses']);

        return view('admin.requests.show', [
            'request' => $rechargeRequest,
            'ledger'  => $rechargeRequest->ledgerEntries()->orderByDesc('id')->get(),
            'symbol'  => $this->settings->currencySymbol(),
        ]);
    }

    /** Approve a held request and release it for dispatch. */
    public function approve(Request $request, RechargeRequest $rechargeRequest): RedirectResponse
    {
        if (! $rechargeRequest->status->isRefundable()) {
            return back()->with('error', 'This request has already been settled.');
        }

        // Claim it so two operators clicking at once cannot both release it.
        $claimed = RechargeRequest::query()
            ->where('id', $rechargeRequest->id)
            ->whereIn('status', [TransactionStatus::Waiting->value])
            ->update([
                'status'      => TransactionStatus::Pending->value,
                'local'       => 0,
                'update_time' => now()->format('Y-m-d H:i:s'),
            ]);

        if ($claimed !== 1) {
            return back()->with('warning', 'That request was already handled by someone else.');
        }

        DispatchRechargeRequest::dispatch($rechargeRequest->id)->afterCommit();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'request.approved',
            sprintf('Approved %s (%s)', $rechargeRequest->serviceLabel(), $rechargeRequest->sid),
            ['request_id' => $rechargeRequest->id],
        );

        return back()->with('status', 'Request approved and queued for dispatch.');
    }

    /** Cancel a request and refund the reseller. */
    public function cancel(Request $request, RechargeRequest $rechargeRequest): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $actor = $request->attributes->get('current_reseller');

        $refunded = $this->recharge->cancelAndRefund(
            $rechargeRequest,
            $actor?->id,
            $validated['reason'] ?? 'cancelled by operator',
        );

        return $refunded
            ? back()->with('status', 'Request cancelled and the balance refunded.')
            : back()->with('warning', 'This request was already settled; nothing was refunded.');
    }

    /** Push a stuck request back to its provider. */
    public function resend(Request $request, RechargeRequest $rechargeRequest): RedirectResponse
    {
        if ($rechargeRequest->status->isFinal()) {
            return back()->with('error', 'A settled request cannot be resent.');
        }

        RechargeRequest::query()
            ->where('id', $rechargeRequest->id)
            ->update([
                'status'       => TransactionStatus::Pending->value,
                'local'        => 0,
                'resend_count' => (int) $rechargeRequest->resend_count + 1,
                'update_time'  => now()->format('Y-m-d H:i:s'),
            ]);

        DispatchRechargeRequest::dispatch($rechargeRequest->id)->afterCommit();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'request.resent',
            sprintf('Resent %s (%s)', $rechargeRequest->serviceLabel(), $rechargeRequest->sid),
            ['request_id' => $rechargeRequest->id],
        );

        return back()->with('status', 'Request queued for another dispatch attempt.');
    }

    /**
     * Apply an action to several requests.
     *
     * Each row is handled independently and the result is reported as a count,
     * so one failure does not silently abandon the rest of the batch.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,cancel'],
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'  => ['integer'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $actor = $request->attributes->get('current_reseller');
        $applied = 0;
        $skipped = 0;

        $requests = RechargeRequest::query()->whereIn('id', $validated['ids'])->get();

        foreach ($requests as $row) {
            if ($validated['action'] === 'cancel') {
                $ok = $this->recharge->cancelAndRefund(
                    $row, $actor?->id, $validated['reason'] ?? 'bulk cancellation',
                );
            } else {
                $ok = RechargeRequest::query()
                    ->where('id', $row->id)
                    ->where('status', TransactionStatus::Waiting->value)
                    ->update([
                        'status'      => TransactionStatus::Pending->value,
                        'local'       => 0,
                        'update_time' => now()->format('Y-m-d H:i:s'),
                    ]) === 1;

                if ($ok) {
                    DispatchRechargeRequest::dispatch($row->id)->afterCommit();
                }
            }

            $ok ? $applied++ : $skipped++;
        }

        $this->audit->record(
            $actor,
            'request.bulk_'.$validated['action'],
            sprintf('Bulk %s: %d applied, %d skipped', $validated['action'], $applied, $skipped),
            ['ids' => $validated['ids']],
        );

        $message = sprintf('%d request(s) %s.', $applied, $validated['action'] === 'cancel' ? 'cancelled' : 'approved');

        if ($skipped > 0) {
            $message .= sprintf(' %d were already settled and left unchanged.', $skipped);
        }

        return back()->with($applied > 0 ? 'status' : 'warning', $message);
    }

    // ------------------------------------------------------------ internals

    /**
     * Apply the filter panel to a query.
     *
     * Every value is bound as a parameter. The legacy equivalents interpolated
     * the date and reseller fields straight into the SQL string, which made the
     * history screens injectable by anyone who could open them.
     */
    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $viewer = $request->attributes->get('current_reseller');

        return RechargeRequest::query()
            ->with('reseller:id,username,name,custype')
            ->when($viewer !== null, fn ($q) => $q->visibleTo($viewer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->when($request->filled('service'), fn ($q) => $q->where('service', $request->integer('service')))
            ->when($request->filled('operator'), fn ($q) => $q->where('pcode', $request->string('operator')->toString()))
            ->when($request->filled('reseller'), fn ($q) => $q->where('userid', $request->integer('reseller')))
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')->toString()))
            ->betweenDates(
                $request->date('from')?->format('Y-m-d'),
                $request->date('to')?->format('Y-m-d'),
            );
    }

    /** Aggregate for the filtered set, computed in one query. */
    private function totals(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $row = $query
            ->getQuery()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS volume, COALESCE(SUM(cost), 0) AS charged')
            ->first();

        return [
            'count'   => (int) ($row->n ?? 0),
            'volume'  => (float) ($row->volume ?? 0),
            'charged' => (float) ($row->charged ?? 0),
        ];
    }

    private function currentFilters(Request $request): array
    {
        return [
            'status'   => $request->input('status'),
            'service'  => $request->input('service'),
            'operator' => $request->input('operator'),
            'reseller' => $request->input('reseller'),
            'q'        => $request->input('q'),
            'from'     => $request->input('from'),
            'to'       => $request->input('to'),
        ];
    }

    private function serviceOptions(): array
    {
        return Service::query()
            ->orderBy('title')
            ->get(['serviceid', 'title'])
            ->mapWithKeys(fn (Service $s) => [
                (int) $s->serviceid => $s->type()?->label() ?? (string) $s->title,
            ])
            ->all();
    }

    /** Hard page-size cap; a client-supplied value cannot exceed it. */
    private function perPage(): int
    {
        return min(100, max(10, (int) config('telecom.ui.per_page', 25)));
    }
}
