@extends('layouts.panel')

@section('title', 'Daily Report')
@section('heading', 'Daily Report')
@section('subheading', 'Day by day over the last ' . $days . ' days')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <form method="GET" action="{{ route('admin.reports.daily') }}" class="card-header">
        <div class="flex items-end gap-3">
            <div>
                <label for="days" class="label">Window</label>
                <select id="days" name="days" class="select">
                    @foreach ([7, 14, 30, 60, 90] as $option)
                        <option value="{{ $option }}" @selected($days === $option)>{{ $option }} days</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-primary btn-sm">Apply</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Date</th><th class="num">Requests</th><th class="num">Volume</th>
                    <th class="num">Charged</th><th class="num">Margin</th>
                    <th class="num">Delivered</th><th class="num">Cancelled</th><th class="num">Success</th></tr>
            </thead>
            <tbody>
                @foreach ($series->reverse() as $row)
                    <tr>
                        <td class="whitespace-nowrap font-medium">{{ $row['label'] }}</td>
                        <td class="num">{{ number_format($row['total']) }}</td>
                        <td class="num">{{ number_format($row['volume'], 2) }}</td>
                        <td class="num">{{ number_format($row['charged'], 2) }}</td>
                        <td class="num" style="color: var(--color-emerald-600);">
                            {{ number_format($row['charged'] - $row['volume'], 2) }}
                        </td>
                        <td class="num">{{ number_format($row['successful']) }}</td>
                        <td class="num">{{ number_format($row['cancelled']) }}</td>
                        <td class="num">
                            {{ $row['total'] > 0 ? round($row['successful'] / $row['total'] * 100, 1) : 0 }}%
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background-color: var(--panel-muted);">
                    <td class="font-semibold">Total</td>
                    <td class="num font-semibold">{{ number_format($series->sum('total')) }}</td>
                    <td class="num font-semibold">{{ number_format($series->sum('volume'), 2) }}</td>
                    <td class="num font-semibold">{{ number_format($series->sum('charged'), 2) }}</td>
                    <td class="num font-semibold" style="color: var(--color-emerald-600);">
                        {{ number_format($series->sum('charged') - $series->sum('volume'), 2) }}
                    </td>
                    <td class="num font-semibold">{{ number_format($series->sum('successful')) }}</td>
                    <td class="num font-semibold">{{ number_format($series->sum('cancelled')) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@endsection
