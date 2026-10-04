@extends('layouts.panel')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', 'Platform activity for ' . now()->format('d M Y'))

@section('content')

@php
    $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2);
    $today = $summary['today'];
    $month = $summary['month'];
    $queue = $summary['queue'];
@endphp

{{-- Headline figures ----------------------------------------------------- --}}

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">

    <x-stat
        label="Today's Volume"
        :value="$money($today['volume'])"
        :meta="$summary['change']['volume'] === null
            ? $today['count'] . ' requests'
            : sprintf('%s%s%% vs yesterday · %d requests',
                $summary['change']['volume'] >= 0 ? '+' : '',
                $summary['change']['volume'],
                $today['count'])"
        icon="chart"
        tone="brand" />

    <x-stat
        label="Today's Margin"
        :value="$money($today['margin'])"
        :meta="'Success rate ' . $today['success_rate'] . '%'"
        icon="wallet"
        tone="emerald" />

    <x-stat
        label="Awaiting Review"
        :value="number_format($queue['waiting'])"
        :meta="$money($queue['waiting_amount']) . ' held'"
        icon="clock"
        :tone="$queue['waiting'] > 0 ? 'amber' : 'brand'" />

    <x-stat
        label="Network Float"
        :value="$money(array_sum($summary['float']))"
        :meta="number_format($summary['network']['active']) . ' active of ' . number_format($summary['network']['total'])"
        icon="users"
        tone="violet" />
</div>

{{-- Queue callout --------------------------------------------------------- --}}

@if ($queue['waiting'] > 0 || $queue['pending'] > 0)
    <div class="card mt-4 flex flex-wrap items-center gap-4 p-4">
        <span class="grid size-9 shrink-0 place-items-center rounded-lg"
              style="background-color: color-mix(in oklch, var(--color-amber-500) 15%, transparent); color: var(--color-amber-600);">
            <x-icon name="alert" class="size-5" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">
                {{ number_format($queue['waiting']) }} waiting for approval,
                {{ number_format($queue['pending']) }} queued for dispatch,
                {{ number_format($queue['processing']) }} with providers
            </p>
            <p class="text-xs text-subtle">Requests above the auto-approval limit need an operator decision.</p>
        </div>
        <a href="{{ route('admin.requests.pending') }}" class="btn btn-primary btn-sm">
            Open queue <x-icon name="chevron-right" class="size-4" />
        </a>
    </div>
@endif

{{-- Trend + service mix --------------------------------------------------- --}}

<div class="mt-4 grid gap-4 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header">
            <div>
                <h2 class="card-title">Volume &amp; Requests</h2>
                <p class="text-xs text-subtle">Last 14 days</p>
            </div>
        </div>
        <div class="card-body">
            <div x-data="chart({
                series: [
                    {
                        name: 'Volume',
                        type: 'area',
                        data: @js($series->pluck('volume')->map(fn ($v) => round($v, 2))->values())
                    },
                    {
                        name: 'Requests',
                        type: 'line',
                        data: @js($series->pluck('total')->values())
                    }
                ],
                chart: { height: 300, type: 'line', stacked: false },
                stroke: { width: [0, 2.5], curve: 'smooth' },
                fill: {
                    type: ['gradient', 'solid'],
                    gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 95] }
                },
                colors: ['#6366f1', '#10b981'],
                xaxis: { categories: @js($series->pluck('label')->values()), tooltip: { enabled: false } },
                yaxis: [
                    { seriesName: 'Volume', labels: { formatter: (v) => Math.round(v).toLocaleString() } },
                    { seriesName: 'Requests', opposite: true, labels: { formatter: (v) => Math.round(v) } }
                ],
                markers: { size: 0, hover: { size: 5 } }
            })" class="w-full"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Service Mix</h2>
                <p class="text-xs text-subtle">This month by volume</p>
            </div>
        </div>
        <div class="card-body">
            @if ($services->isEmpty())
                <x-empty icon="box" title="No traffic yet this month" />
            @else
                <div x-data="chart({
                    series: @js($services->take(6)->pluck('volume')->map(fn ($v) => round($v, 2))->values()),
                    labels: @js($services->take(6)->pluck('label')->values()),
                    chart: { type: 'donut', height: 260 },
                    colors: ['#6366f1', '#10b981', '#f59e0b', '#38bdf8', '#a78bfa', '#f43f5e'],
                    legend: { position: 'bottom', horizontalAlign: 'center' },
                    plotOptions: { pie: { donut: { size: '68%' } } },
                    stroke: { width: 0 }
                })"></div>
            @endif
        </div>
    </div>
</div>

{{-- Operators, top resellers, providers ----------------------------------- --}}

<div class="mt-4 grid gap-4 lg:grid-cols-3">

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Operators</h2>
            <a href="{{ route('admin.reports.operators') }}" class="btn btn-ghost btn-sm">Report</a>
        </div>
        <div class="table-wrap">
            @if ($operators->isEmpty())
                <x-empty icon="phone" title="No operator traffic" />
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th>Operator</th>
                            <th class="num">Volume</th>
                            <th class="num">Success</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operators->take(8) as $operator)
                            <tr>
                                <td class="font-medium">{{ $operator['code'] }}</td>
                                <td class="num">{{ number_format($operator['volume']) }}</td>
                                <td class="num">
                                    <span class="badge"
                                          @class(['tnum'])
                                          style="background-color: color-mix(in oklch, {{ $operator['success_rate'] >= 90 ? 'var(--color-emerald-500)' : ($operator['success_rate'] >= 70 ? 'var(--color-amber-500)' : 'var(--color-rose-500)') }} 15%, transparent);
                                                 color: {{ $operator['success_rate'] >= 90 ? 'var(--color-emerald-700)' : ($operator['success_rate'] >= 70 ? 'var(--color-amber-700)' : 'var(--color-rose-700)') }};">
                                        {{ $operator['success_rate'] }}%
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Top Resellers</h2>
            <a href="{{ route('admin.resellers.index') }}" class="btn btn-ghost btn-sm">All</a>
        </div>
        <div class="table-wrap">
            @if ($top->isEmpty())
                <x-empty icon="users" title="No reseller activity" />
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reseller</th>
                            <th class="num">Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($top as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.resellers.show', $row['id']) }}"
                                       class="font-medium hover:underline">{{ $row['name'] }}</a>
                                    <p class="text-xs text-subtle">{{ $row['level'] }} · {{ $row['total'] }} requests</p>
                                </td>
                                <td class="num">{{ number_format($row['volume']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Provider Balance</h2>
            <a href="{{ route('admin.providers.index') }}" class="btn btn-ghost btn-sm">Manage</a>
        </div>
        <div class="card-body space-y-3">
            @forelse ($providers as $provider)
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $provider['name'] }}</p>
                        @if ($provider['low'])
                            <p class="text-xs" style="color: var(--color-rose-600);">Low balance</p>
                        @endif
                    </div>
                    <p class="shrink-0 text-sm font-semibold tnum">{{ $money($provider['balance']) }}</p>
                </div>
            @empty
                <x-empty icon="server" title="No active providers"
                         message="Add a provider to dispatch requests automatically." />
            @endforelse

            <div class="border-t pt-3" style="border-color: var(--line);">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted">Month volume</span>
                    <span class="font-semibold tnum">{{ $money($month['volume']) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-sm">
                    <span class="text-muted">Month margin</span>
                    <span class="font-semibold tnum" style="color: var(--color-emerald-600);">
                        {{ $money($month['margin']) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Recent activity ------------------------------------------------------- --}}

<div class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">Recent Requests</h2>
        <a href="{{ route('admin.requests.index') }}" class="btn btn-secondary btn-sm">
            View all <x-icon name="chevron-right" class="size-4" />
        </a>
    </div>
    <div class="table-wrap">
        @if ($recent->isEmpty())
            <x-empty icon="list" title="No requests yet" />
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Reseller</th>
                        <th>Service</th>
                        <th>Number</th>
                        <th class="num">Amount</th>
                        <th class="num">Charged</th>
                        <th>Status</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $request)
                        <tr>
                            <td>
                                <a href="{{ route('admin.requests.show', $request) }}"
                                   class="font-mono text-xs hover:underline">
                                    {{ Str::limit($request->sid, 14, '') }}
                                </a>
                            </td>
                            <td class="text-sm">{{ $request->reseller?->displayName() ?? '—' }}</td>
                            <td class="text-sm">{{ $request->serviceLabel() }}</td>
                            <td class="font-mono text-sm">{{ $request->phone }}</td>
                            <td class="num">{{ number_format((float) $request->balance) }}</td>
                            <td class="num">{{ number_format((float) $request->cost, 2) }}</td>
                            <td><x-status-badge :status="$request->status" /></td>
                            <td class="text-xs text-subtle">
                                {{ $request->submitted_date?->diffForHumans(short: true) ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@endsection
