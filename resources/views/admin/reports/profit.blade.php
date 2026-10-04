@extends('layouts.panel')

@section('title', 'Profit Report')
@section('heading', 'Profit Report')
@section('subheading', $from . ' to ' . $to)

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <form method="GET" action="{{ route('admin.reports.profit') }}" class="card-header">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="from" class="label">From</label>
                <input id="from" name="from" type="date" class="input" value="{{ $from }}">
            </div>
            <div>
                <label for="to" class="label">To</label>
                <input id="to" name="to" type="date" class="input" value="{{ $to }}">
            </div>
            <button class="btn btn-primary btn-sm">Apply</button>
        </div>
    </form>

    <div class="table-wrap">
        @if ($rows->isEmpty())
            <x-empty icon="chart" title="No settled transactions in this range" />
        @else
            <table class="table">
                <thead>
                    <tr><th>Service</th><th class="num">Requests</th><th class="num">Face value</th>
                        <th class="num">Charged to resellers</th><th class="num">Margin</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="font-medium">{{ $row['label'] }}</td>
                            <td class="num">{{ number_format($row['total']) }}</td>
                            <td class="num">{{ number_format($row['volume'], 2) }}</td>
                            <td class="num">{{ number_format($row['charged'], 2) }}</td>
                            <td class="num font-medium"
                                style="color: var(--color-{{ $row['margin'] >= 0 ? 'emerald' : 'rose' }}-600);">
                                {{ number_format($row['margin'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background-color: var(--panel-muted);">
                        <td class="font-semibold">Total</td>
                        <td class="num font-semibold">{{ number_format($rows->sum('total')) }}</td>
                        <td class="num font-semibold">{{ number_format($rows->sum('volume'), 2) }}</td>
                        <td class="num font-semibold">{{ number_format($rows->sum('charged'), 2) }}</td>
                        <td class="num font-semibold" style="color: var(--color-emerald-600);">
                            {{ number_format($rows->sum('margin'), 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0 justify-end">
        <a href="{{ route('admin.reports.export', ['from' => $from, 'to' => $to]) }}" class="btn btn-secondary btn-sm">
            <x-icon name="download" class="size-4" /> Export CSV
        </a>
    </div>
</div>

@endsection
