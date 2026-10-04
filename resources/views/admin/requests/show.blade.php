@extends('layouts.panel')

@section('title', 'Request ' . Str::limit($request->sid, 12, ''))
@section('heading', $request->serviceLabel() . ' request')
@section('subheading', $request->sid)

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="grid gap-4 lg:grid-cols-3">

    <div class="space-y-4 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Details</h2>
                <x-status-badge :status="$request->status" />
            </div>
            <div class="card-body">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ([
                        'Destination number' => $request->phone,
                        'Operator' => $request->pcode . ' (' . $request->operator . ')',
                        'Service' => $request->serviceLabel(),
                        'Connection type' => $request->type === '2' ? 'Postpaid' : 'Prepaid',
                        'Face amount' => $money($request->balance),
                        'Reseller charged' => $money($request->cost),
                        'Upline cost' => $money($request->parent_cost),
                        'Margin' => $money($request->margin()),
                        'Funded from' => $request->wallet()->label(),
                        'Balance before' => $money($request->prebalance),
                        'Submitted' => $request->submitted_date?->format('d M Y, H:i:s') ?? '—',
                        'Provider txn' => $request->trxid ?: '—',
                        'Channel' => $request->remark ?: '—',
                        'Source IP' => $request->ip ?: '—',
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-subtle">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Provider Responses</h2></div>
            <div class="table-wrap">
                @if ($request->providerResponses->isEmpty())
                    <x-empty icon="server" title="No provider responses yet" />
                @else
                    <table class="table">
                        <thead><tr><th>Result</th><th>Detail</th><th>When</th></tr></thead>
                        <tbody>
                            @foreach ($request->providerResponses as $response)
                                <tr>
                                    <td class="text-sm font-medium">{{ $response->msg }}</td>
                                    <td class="max-w-md truncate text-xs text-subtle">{{ $response->body }}</td>
                                    <td class="whitespace-nowrap text-xs text-subtle">{{ $response->time }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Ledger</h2></div>
            <div class="table-wrap">
                @if ($ledger->isEmpty())
                    <x-empty icon="receipt" title="No ledger lines" />
                @else
                    <table class="table">
                        <thead>
                            <tr><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th><th>When</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger as $entry)
                                <tr>
                                    <td class="text-sm">{{ $entry->desc }}</td>
                                    <td class="num">{{ (float) $entry->debit > 0 ? number_format((float) $entry->debit, 2) : '—' }}</td>
                                    <td class="num">{{ (float) $entry->credit > 0 ? number_format((float) $entry->credit, 2) : '—' }}</td>
                                    <td class="num">{{ number_format((float) $entry->accountbalance, 2) }}</td>
                                    <td class="whitespace-nowrap text-xs text-subtle">{{ $entry->time }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Reseller</h2></div>
            <div class="card-body">
                @if ($request->reseller)
                    <p class="font-medium">{{ $request->reseller->displayName() }}</p>
                    <p class="text-sm text-subtle">{{ $request->reseller->username }} · {{ $request->reseller->level()?->defaultLabel() }}</p>
                    <p class="mt-2 text-sm">{{ $request->reseller->mobile }}</p>
                    <a href="{{ route('admin.resellers.show', $request->reseller) }}" class="btn btn-secondary btn-sm mt-3">
                        Open account
                    </a>
                @else
                    <p class="text-sm text-subtle">This account has been removed.</p>
                @endif
            </div>
        </div>

        @if ($request->status->isRefundable())
            <div class="card">
                <div class="card-header"><h2 class="card-title">Actions</h2></div>
                <div class="card-body space-y-3">
                    @if ($request->status === \App\Enums\TransactionStatus::Waiting)
                        <form method="POST" action="{{ route('admin.requests.approve', $request) }}">
                            @csrf
                            <button class="btn btn-primary w-full">
                                <x-icon name="check" class="size-4" /> Approve &amp; dispatch
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.requests.resend', $request) }}">
                        @csrf
                        <button class="btn btn-secondary w-full">
                            <x-icon name="refresh" class="size-4" /> Resend to provider
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.requests.cancel', $request) }}" class="space-y-2">
                        @csrf
                        <input name="reason" class="input" placeholder="Reason (optional)" maxlength="200">
                        <button class="btn btn-danger w-full"
                                onclick="return confirm('Cancel this request and refund the reseller?')">
                            <x-icon name="x" class="size-4" /> Cancel &amp; refund
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>

@endsection
