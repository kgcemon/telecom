@extends('layouts.panel')

@section('title', 'Ledger')
@section('heading', 'Ledger')
@section('subheading', $resellerName ? 'Statement for ' . $resellerName : 'Every wallet movement across the network')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('admin.ledger.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-reseller" class="label">Reseller ID</label>
            <input id="f-reseller" name="reseller" class="input w-36" value="{{ $filters['reseller'] ?? '' }}">
        </div>
        <div>
            <label for="f-type" class="label">Type</label>
            <select id="f-type" name="type" class="select">
                <option value="">All</option>
                @foreach (['minus' => 'Debit', 'plus' => 'Credit', 'refund' => 'Refund'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-q" class="label">Description</label>
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
        'Lines' => number_format($totals['count']),
        'Debits' => $money($totals['debits']),
        'Credits' => $money($totals['credits']),
        'Net' => $money($totals['credits'] - $totals['debits']),
    ]" />

    <div class="table-wrap">
        @if ($entries->isEmpty())
            <x-empty icon="receipt" title="No ledger lines match" />
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Reseller</th><th>Description</th>
                        <th class="num">Opening</th><th class="num">Debit</th>
                        <th class="num">Credit</th><th class="num">Closing</th><th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="text-sm">{{ $entry->reseller?->displayName() ?? $entry->userid }}</td>
                            <td class="max-w-md truncate text-sm">{{ $entry->desc }}</td>
                            <td class="num text-subtle">{{ number_format((float) $entry->oldbal, 2) }}</td>
                            <td class="num" style="color: var(--color-rose-600);">
                                {{ (float) $entry->debit > 0 ? number_format((float) $entry->debit, 2) : '—' }}
                            </td>
                            <td class="num" style="color: var(--color-emerald-600);">
                                {{ (float) $entry->credit > 0 ? number_format((float) $entry->credit, 2) : '—' }}
                            </td>
                            <td class="num font-medium">{{ number_format((float) $entry->accountbalance, 2) }}</td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $entry->time }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($entries->hasPages())
        <div class="card-header border-t border-b-0">{{ $entries->links() }}</div>
    @endif
</div>

@endsection
