@extends('layouts.panel')

@section('title', 'Reports')
@section('heading', 'Reports')
@section('subheading', 'Volume, margin and delivery quality')

@section('content')

@php
    $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2);
    $month = $summary['month'];
@endphp

<div class="mb-4 flex flex-wrap gap-2">
    @foreach ([
        'admin.reports.index' => 'Overview',
        'admin.reports.daily' => 'Daily',
        'admin.reports.operators' => 'Operators',
        'admin.reports.profit' => 'Profit',
    ] as $route => $label)
        <a href="{{ route($route) }}"
           class="btn {{ request()->routeIs($route) ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
    @endforeach
</div>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat label="Volume This Month" :value="$money($month['volume'])"
            :meta="number_format($month['count']) . ' requests'" icon="chart" tone="brand" />
    <x-stat label="Margin This Month" :value="$money($month['margin'])" icon="wallet" tone="emerald" />
    <x-stat label="Success Rate" :value="$month['success_rate'] . '%'"
            :meta="number_format($month['successful']) . ' delivered'" icon="check" tone="sky" />
    <x-stat label="Cancelled" :value="number_format($month['cancelled'])"
            meta="Refunded to resellers" icon="x" tone="rose" />
</div>

<div class="card mt-4">
    <div class="card-header">
        <div>
            <h2 class="card-title">Last 30 Days</h2>
            <p class="text-xs text-subtle">Face value against requests</p>
        </div>
        <a href="{{ route('admin.reports.export') }}" class="btn btn-secondary btn-sm">
            <x-icon name="download" class="size-4" /> Export CSV
        </a>
    </div>
    <div class="card-body">
        <div x-data="chart({
            series: [
                { name: 'Volume', type: 'column', data: @js($series->pluck('volume')->map(fn ($v) => round($v, 2))->values()) },
                { name: 'Successful', type: 'line', data: @js($series->pluck('successful')->values()) }
            ],
            chart: { height: 320, type: 'line', stacked: false },
            stroke: { width: [0, 2.5], curve: 'smooth' },
            colors: ['#6366f1', '#10b981'],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '55%' } },
            xaxis: { categories: @js($series->pluck('label')->values()) },
            yaxis: [
                { seriesName: 'Volume', labels: { formatter: (v) => Math.round(v).toLocaleString() } },
                { seriesName: 'Successful', opposite: true, labels: { formatter: (v) => Math.round(v) } }
            ]
        })"></div>
    </div>
</div>

@endsection
