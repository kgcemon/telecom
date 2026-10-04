@extends('layouts.panel')

@section('title', 'Request')
@section('heading', $request->serviceLabel())
@section('subheading', $request->sid)

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card max-w-2xl">
    <div class="card-header">
        <h2 class="card-title">Receipt</h2>
        <x-status-badge :status="$request->status" />
    </div>

    <div class="card-body">
        <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
            @foreach ([
                'Number' => $request->phone,
                'Operator' => $request->pcode ?: '—',
                'Service' => $request->serviceLabel(),
                'Amount delivered' => $money($request->balance),
                'You paid' => $money($request->cost),
                'Paid from' => $request->wallet()->label(),
                'Balance before' => $money($request->prebalance),
                'Reference' => $request->sid,
                'Operator txn' => $request->trxid ?: 'Not issued yet',
                'Submitted' => $request->submitted_date?->format('d M Y, H:i:s') ?? '—',
            ] as $label => $value)
                <div>
                    <dt class="text-xs uppercase tracking-wide text-subtle">{{ $label }}</dt>
                    <dd class="mt-0.5 text-sm font-medium">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($request->status === \App\Enums\TransactionStatus::Waiting)
            <div class="mt-5 rounded-lg p-3 text-sm"
                 style="background-color: color-mix(in oklch, var(--color-amber-500) 10%, transparent);">
                This request is above the automatic limit and is waiting for an operator to approve it.
                Your balance is already reserved.
            </div>
        @elseif ($request->status === \App\Enums\TransactionStatus::Cancelled)
            <div class="mt-5 rounded-lg p-3 text-sm"
                 style="background-color: color-mix(in oklch, var(--color-rose-500) 10%, transparent);">
                This request was cancelled and {{ $money($request->cost) }} has been returned to your balance.
            </div>
        @endif
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('reseller.recharge.index') }}" class="btn btn-ghost">Back to list</a>
        <a href="{{ route('reseller.recharge.create') }}" class="btn btn-primary">New recharge</a>
    </div>
</div>

@endsection
