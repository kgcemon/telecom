@extends('layouts.panel')

@section('title', 'Dashboard')
@section('heading', 'Welcome back, ' . $user->displayName())
@section('subheading', $user->level()?->defaultLabel() . ' · ' . now()->format('d M Y'))

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

@foreach ($notices as $notice)
    <div class="card mb-4 flex items-start gap-3 p-4">
        <span class="grid size-9 shrink-0 place-items-center rounded-lg"
              style="background-color: color-mix(in oklch, var(--color-sky-500) 14%, transparent); color: var(--color-sky-600);">
            <x-icon name="bell" class="size-5" />
        </span>
        <div class="min-w-0">
            <p class="text-sm font-medium">{{ $notice->subject }}</p>
            <p class="mt-0.5 text-sm text-muted">{{ strip_tags((string) $notice->notice) }}</p>
        </div>
    </div>
@endforeach

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat label="Main Balance" :value="$money($user->balance)"
            :meta="(float) $user->balance_limit > 0 ? 'Credit limit ' . $money($user->balance_limit) : 'No credit limit'"
            icon="wallet" tone="brand" />

    <x-stat label="Today" :value="$money($summary['today']['volume'])"
            :meta="number_format($summary['today']['count']) . ' requests'" icon="chart" tone="sky" />

    <x-stat label="This Month" :value="$money($summary['month']['volume'])"
            :meta="number_format($summary['month']['count']) . ' requests'" icon="clock" tone="violet" />

    <x-stat label="You Saved This Month" :value="$money(abs($summary['month']['savings']))"
            meta="Face value delivered minus what you paid" icon="tag" tone="emerald" />
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header">
            <div>
                <h2 class="card-title">Your Volume</h2>
                <p class="text-xs text-subtle">Last 14 days</p>
            </div>
            <a href="{{ route('reseller.recharge.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="size-4" /> New recharge
            </a>
        </div>
        <div class="card-body">
            <div x-data="chart({
                series: [{ name: 'Volume', data: @js($series->pluck('volume')->map(fn ($v) => round($v, 2))->values()) }],
                chart: { type: 'area', height: 280 },
                colors: ['#6366f1'],
                stroke: { width: 2.5, curve: 'smooth' },
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 95] } },
                xaxis: { categories: @js($series->pluck('label')->values()), tooltip: { enabled: false } },
                yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString() } },
                markers: { size: 0, hover: { size: 5 } }
            })"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">This Month</h2></div>
        <div class="card-body space-y-3">
            @foreach ([
                ['Successful', $summary['statuses']['success'], 'emerald'],
                ['Waiting approval', $summary['statuses']['waiting'], 'amber'],
                ['In progress', $summary['statuses']['pending'], 'sky'],
                ['Cancelled', $summary['statuses']['cancelled'], 'rose'],
            ] as [$label, $count, $tone])
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-2 text-sm text-muted">
                        <span class="size-2 rounded-full" style="background-color: var(--color-{{ $tone }}-500);"></span>
                        {{ $label }}
                    </span>
                    <span class="font-semibold tnum">{{ number_format($count) }}</span>
                </div>
            @endforeach

            <div class="border-t pt-3" style="border-color: var(--line);">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted">Accounts under you</span>
                    <span class="font-semibold tnum">{{ number_format($summary['downline']) }}</span>
                </div>
            </div>

            <a href="{{ route('reseller.deposit.create') }}" class="btn btn-secondary w-full">
                <x-icon name="plus" class="size-4" /> Add balance
            </a>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">Recent Requests</h2>
        <a href="{{ route('reseller.recharge.index') }}" class="btn btn-secondary btn-sm">View all</a>
    </div>
    <div class="table-wrap">
        @if ($recent->isEmpty())
            <x-empty icon="phone" title="No requests yet"
                     message="Your recharges will appear here as soon as you send one.">
                <a href="{{ route('reseller.recharge.create') }}" class="btn btn-primary btn-sm">Send a recharge</a>
            </x-empty>
        @else
            <table class="table">
                <thead>
                    <tr><th>Number</th><th>Service</th><th class="num">Amount</th><th class="num">You paid</th><th>Status</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($recent as $item)
                        <tr>
                            <td>
                                <a href="{{ route('reseller.recharge.show', $item) }}" class="font-mono text-sm hover:underline">
                                    {{ $item->phone }}
                                </a>
                            </td>
                            <td class="text-sm">{{ $item->serviceLabel() }}</td>
                            <td class="num">{{ number_format((float) $item->balance) }}</td>
                            <td class="num">{{ number_format((float) $item->cost, 2) }}</td>
                            <td><x-status-badge :status="$item->status" /></td>
                            <td class="text-xs text-subtle">{{ $item->submitted_date?->diffForHumans(short: true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@endsection
