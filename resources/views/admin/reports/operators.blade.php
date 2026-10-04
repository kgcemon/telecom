@extends('layouts.panel')

@section('title', 'Operator Report')
@section('heading', 'Operator Report')
@section('subheading', 'Volume and delivery quality by network, this month')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="grid gap-4 lg:grid-cols-2">

    <div class="card">
        <div class="card-header"><h2 class="card-title">By Operator</h2></div>
        <div class="table-wrap">
            @if ($operators->isEmpty())
                <x-empty icon="phone" title="No traffic this month" />
            @else
                <table class="table">
                    <thead><tr><th>Operator</th><th class="num">Requests</th><th class="num">Volume</th><th class="num">Success rate</th></tr></thead>
                    <tbody>
                        @foreach ($operators as $row)
                            <tr>
                                <td class="font-medium">{{ $row['code'] }}</td>
                                <td class="num">{{ number_format($row['total']) }}</td>
                                <td class="num">{{ number_format($row['volume'], 2) }}</td>
                                <td class="num">
                                    @php $tone = $row['success_rate'] >= 90 ? 'emerald' : ($row['success_rate'] >= 70 ? 'amber' : 'rose'); @endphp
                                    <span class="badge"
                                          style="background-color: color-mix(in oklch, var(--color-{{ $tone }}-500) 15%, transparent); color: var(--color-{{ $tone }}-700);">
                                        {{ $row['success_rate'] }}%
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
        <div class="card-header"><h2 class="card-title">By Service</h2></div>
        <div class="table-wrap">
            @if ($services->isEmpty())
                <x-empty icon="box" title="No traffic this month" />
            @else
                <table class="table">
                    <thead><tr><th>Service</th><th class="num">Requests</th><th class="num">Volume</th></tr></thead>
                    <tbody>
                        @foreach ($services as $row)
                            <tr>
                                <td class="font-medium">{{ $row['label'] }}</td>
                                <td class="num">{{ number_format($row['total']) }}</td>
                                <td class="num">{{ number_format($row['volume'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

@endsection
