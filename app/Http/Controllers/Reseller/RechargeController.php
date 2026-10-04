<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceType;
use App\Enums\TransactionStatus;
use App\Models\DrivePackage;
use App\Models\InternetPackage;
use App\Models\RechargeRequest;
use App\Models\Reseller;
use App\Models\Service;
use App\Services\Recharge\Dto\RechargeInput;
use App\Services\Recharge\OperatorResolver;
use App\Services\Recharge\PricingService;
use App\Services\Recharge\RechargeService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The reseller-facing recharge flow.
 *
 * The form is built around three small JSON endpoints - operator lookup, live
 * quote and package list - so the reseller sees the operator and the exact
 * amount they will be charged *before* committing, with their PIN entered last.
 * The legacy form posted blind and the reseller only learned the cost from the
 * balance afterwards, which was the single most common source of disputes.
 *
 * The quote shown by those endpoints is advisory. The authoritative price is
 * recomputed inside RechargeService under the balance lock, so a rate change
 * between preview and submit cannot be exploited.
 */
class RechargeController
{
    public function __construct(
        private readonly RechargeService $recharge,
        private readonly PricingService $pricing,
        private readonly OperatorResolver $operators,
        private readonly SettingsService $settings,
    ) {}

    public function create(Request $request): View
    {
        $user = $this->user($request);

        return view('reseller.recharge.create', [
            'services'  => $this->availableServices(),
            'operators' => $this->operatorOptions(),
            'symbol'    => $this->settings->currencySymbol(),
            'user'      => $user,
            'quickAmounts' => [20, 50, 100, 200, 300, 500, 1000],
            'recent'    => RechargeRequest::query()
                ->where('userid', $user->id)
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'service'  => ['required', 'integer', Rule::in($this->availableServices()->pluck('id')->all())],
            'number'   => ['required', 'string', 'min:4', 'max:30'],
            'amount'   => ['required', 'numeric', 'min:1', 'max:'.(int) config('telecom.limits.max_single_request')],
            'type'     => ['required', 'string', Rule::in(['1', '2', '3', '4'])],
            'operator' => ['nullable', 'string', 'max:10'],
            'package'  => ['nullable', 'integer'],
            'pin'      => ['nullable', 'string', 'max:20'],
            'name'     => ['nullable', 'string', 'max:120'],
            'nid'      => ['nullable', 'string', 'max:40'],
            'sender'   => ['nullable', 'string', 'max:20'],
            'remark'   => ['nullable', 'string', 'max:160'],
        ], [], [
            'number' => 'mobile number',
            'pin'    => 'transaction PIN',
        ]);

        $service = ServiceType::tryFrom((int) $validated['service']);

        if ($service === null) {
            return back()->withInput()->with('error', 'That service is not available.');
        }

        $outcome = $this->recharge->place(new RechargeInput(
            reseller: $user,
            service: $service,
            number: $validated['number'],
            amount: (float) $validated['amount'],
            numberType: $validated['type'],
            pin: $validated['pin'] ?? null,
            operatorCode: $validated['operator'] ?? null,
            packageId: $validated['package'] ?? null,
            customerName: $validated['name'] ?? null,
            nationalId: $validated['nid'] ?? null,
            senderNumber: $validated['sender'] ?? null,
            remark: $validated['remark'] ?? null,
            channel: 'web',
            ipAddress: $request->ip(),
        ));

        if (! $outcome->successful) {
            // Keep everything except the PIN, so the reseller can correct one
            // field without retyping the whole form.
            return back()
                ->withInput($request->except('pin'))
                ->with('error', $outcome->message);
        }

        return redirect()
            ->route('reseller.recharge.show', $outcome->request)
            ->with('status', $outcome->message);
    }

    public function index(Request $request): View
    {
        $user = $this->user($request);

        $query = RechargeRequest::query()
            ->where('userid', $user->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->when($request->filled('service'), fn ($q) => $q->where('service', $request->integer('service')))
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')->toString()))
            ->betweenDates(
                $request->date('from')?->format('Y-m-d'),
                $request->date('to')?->format('Y-m-d'),
            );

        $totals = (clone $query)->getQuery()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS volume, COALESCE(SUM(cost), 0) AS charged')
            ->first();

        return view('reseller.recharge.index', [
            'requests' => $query->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'services' => $this->availableServices(),
            'statuses' => TransactionStatus::cases(),
            'symbol'   => $this->settings->currencySymbol(),
            'filters'  => $request->only(['status', 'service', 'q', 'from', 'to']),
            'totals'   => [
                'count'   => (int) ($totals->n ?? 0),
                'volume'  => (float) ($totals->volume ?? 0),
                'charged' => (float) ($totals->charged ?? 0),
            ],
        ]);
    }

    public function show(Request $request, RechargeRequest $rechargeRequest): View
    {
        // A reseller may only open their own request. Without this check the
        // id in the URL would expose every other reseller's traffic - which is
        // exactly how the legacy details page behaved.
        abort_unless((int) $rechargeRequest->userid === (int) $this->user($request)->id, 404);

        return view('reseller.recharge.show', [
            'request' => $rechargeRequest,
            'symbol'  => $this->settings->currencySymbol(),
        ]);
    }

    // -------------------------------------------------------- JSON helpers

    /** Identify the operator as the reseller types the number. */
    public function lookupOperator(Request $request): JsonResponse
    {
        $number = (string) $request->string('number');
        $prefix = $this->operators->prefixOf($number);
        $code = $this->operators->resolve($number);

        return response()->json([
            'prefix'   => $prefix,
            'operator' => $code,
            'enabled'  => $code !== null && $this->operators->isPrefixEnabled($prefix),
            'expected_length' => $this->operators->expectedLength($prefix),
            'normalised' => $this->operators->normaliseNumber($number),
        ]);
    }

    /**
     * Price preview.
     *
     * Advisory only - see the class docblock. It never reserves balance and
     * never creates a row.
     */
    public function quote(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'service' => ['required', 'integer'],
            'number'  => ['required', 'string', 'max:30'],
            'amount'  => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'type'    => ['nullable', 'string', 'max:2'],
            'package' => ['nullable', 'integer'],
        ]);

        $service = ServiceType::tryFrom((int) $validated['service']);

        if ($service === null) {
            return response()->json(['ok' => false, 'message' => 'Unknown service.'], 422);
        }

        $quote = $this->pricing->quote(
            reseller: $user,
            service: $service,
            amount: (float) $validated['amount'],
            number: $validated['number'],
            numberType: $validated['type'] ?? '1',
            packageId: $validated['package'] ?? null,
        );

        $wallet = $this->settings->security()->walletFor(
            $service,
            isPackage: ($validated['package'] ?? null) !== null,
        );

        $available = $user->spendableBalance($wallet);
        $payable = $quote->payable();

        return response()->json([
            'ok'         => $quote->resolved,
            'resolved'   => $quote->resolved,
            'operator'   => $quote->operatorCode,
            'face'       => round($quote->faceAmount, 2),
            'commission' => $quote->commissionAmount(),
            'charge'     => $quote->chargeAmount(),
            'payable'    => $payable,
            'wallet'     => $wallet->label(),
            'available'  => round($available, 2),
            'affordable' => $available >= $payable,
            'message'    => $quote->resolved
                ? null
                : 'This operator and amount is not priced yet. Please contact support.',
        ]);
    }

    /** Bundles available for the chosen service and operator. */
    public function packages(Request $request): JsonResponse
    {
        $service = ServiceType::tryFrom($request->integer('service'));
        $operator = $this->operators->normaliseCode($request->string('operator')->toString());

        $packages = match ($service) {
            ServiceType::InternetPack => InternetPackage::query()
                ->where('status', 1)
                ->when($operator, fn ($q) => $q->where('op', $operator))
                ->orderBy('price')
                ->get(['id', 'pk_name', 'volume', 'price', 'comm', 'charge']),
            ServiceType::Flexiload => DrivePackage::query()
                ->where('status', 1)
                ->when($operator, fn ($q) => $q->where('op', $operator))
                ->orderBy('price')
                ->get(['id', 'pk_name', 'volume', 'price', 'comm', 'charge']),
            default => collect(),
        };

        return response()->json([
            'packages' => $packages->map(fn ($p) => [
                'id'     => (int) $p->id,
                'name'   => (string) $p->pk_name,
                'volume' => (string) $p->volume,
                'price'  => (float) $p->price,
                'payable' => round((float) $p->price + (float) $p->charge - (float) $p->comm, 2),
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------ internals

    /** Services the reseller may sell, enabled and ordered for display. */
    private function availableServices(): \Illuminate\Support\Collection
    {
        return Service::query()
            ->enabled()
            ->ordered()
            ->get()
            ->filter(fn (Service $s) => $s->type() !== null)
            ->map(fn (Service $s) => [
                'id'          => (int) $s->serviceid,
                'label'       => $s->type()?->label() ?? (string) $s->title,
                'icon'        => $s->type()?->icon() ?? 'grid',
                'min'         => (float) $s->min_amount,
                'max'         => (float) $s->max_amount,
                'min_length'  => (int) $s->min_length,
                'max_length'  => (int) $s->max_length,
                'needs_pin'   => $s->requiresPin(),
                'needs_name'  => $s->requiresCustomerName(),
                'needs_nid'   => $s->requiresNid(),
                'needs_sender' => $s->requiresSenderNumber(),
                'has_packages' => in_array($s->type(), [ServiceType::InternetPack, ServiceType::Flexiload], true),
                'auto_limit'  => $s->autoApprovalLimit(),
            ])
            ->values();
    }

    private function operatorOptions(): array
    {
        return \App\Models\Operator::query()
            ->orderBy('title_op')
            ->get(['opname', 'title_op', 'ex'])
            ->filter(fn ($o) => filled($o->ex))
            ->mapWithKeys(fn ($o) => [(string) $o->ex => (string) $o->title_op])
            ->all();
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller') ?? auth()->guard('reseller')->user();

        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
