@extends('layouts.panel')

@section('title', 'My Requests')
@section('heading', 'My Requests')
@section('subheading', 'Everything you have sent')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('reseller.recharge.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-status" class="label">Status</label>
            <select id="f-status" name="status" class="select">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected((string) ($filters['status'] ?? '') === (string) $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-service" class="label">Service</label>
            <select id="f-service" name="service" class="select">
                <option value="">All</option>
                @foreach ($services as $service)
                    <option value="{{ $service['id'] }}" @selected((string) ($filters['service'] ?? '') === (string) $service['id'])>
                        {{ $service['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-q" class="label">Number</label>
            <input id="f-q" name="q" class="input" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" class="input" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" class="input" value="{{ $filters['to'] ?? '' }}">
        </div>
    </x-filter-bar>

    <x-totals-bar :items="[
        'Requests' => number_format($totals['count']),
        'Face value' => $money($totals['volume']),
        'You paid' => $money($totals['charged']),
    ]" />

    <div class="table-wrap">
        @if ($requests->isEmpty())
            <x-empty icon="list" title="Nothing matches" message="Try clearing the filters." />
        @else
            <table class="table">
                <thead>
                    <tr><th>Reference</th><th>Date</th><th>Service</th><th>Number</th>
                        <th class="num">Amount</th><th class="num">You paid</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($requests as $request)
                        <tr>
                            <td>
                                <a href="{{ route('reseller.recharge.show', $request) }}" class="font-mono text-xs hover:underline">
                                    {{ Str::limit($request->sid, 12, '') }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $request->submitted_date?->format('d M y H:i') }}</td>
                            <td class="text-sm">{{ $request->serviceLabel() }}</td>
                            <td class="font-mono text-sm">{{ $request->phone }}</td>
                            <td class="num">{{ number_format((float) $request->balance) }}</td>
                            <td class="num">{{ number_format((float) $request->cost, 2) }}</td>
                            <td><x-status-badge :status="$request->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($requests->hasPages())
        <div class="card-header border-t border-b-0">{{ $requests->links() }}</div>
    @endif
</div>

@endsection
