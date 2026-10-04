@extends('layouts.panel')

@section('title', 'Statement')
@section('heading', 'Statement')
@section('subheading', 'Every movement on your wallet')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('reseller.statement.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-type" class="label">Type</label>
            <select id="f-type" name="type" class="select">
                <option value="">All</option>
                <option value="minus" @selected(($filters['type'] ?? '') === 'minus')>Spent</option>
                <option value="plus" @selected(($filters['type'] ?? '') === 'plus')>Received</option>
                <option value="refund" @selected(($filters['type'] ?? '') === 'refund')>Refunds</option>
            </select>
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
        'Current balance' => $money($user->balance),
        'Spent' => $money($totals['debits']),
        'Received' => $money($totals['credits']),
    ]" />

    <div class="table-wrap">
        @if ($entries->isEmpty())
            <x-empty icon="receipt" title="No movements yet" />
        @else
            <table class="table">
                <thead>
                    <tr><th>Description</th><th class="num">Out</th><th class="num">In</th><th class="num">Balance</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="max-w-md truncate text-sm">{{ $entry->desc }}</td>
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
