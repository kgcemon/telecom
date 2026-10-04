@extends('layouts.panel')

@section('title', 'Payments')
@section('heading', 'Payments')
@section('subheading', 'Balance added to and returned from resellers')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('admin.payments.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-reseller" class="label">Reseller ID</label>
            <input id="f-reseller" name="reseller" class="input w-36" value="{{ $filters['reseller'] ?? '' }}">
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
        'Added' => $money($totals['credits']),
        'Returned' => $money($totals['debits']),
        'Net' => $money($totals['credits'] - $totals['debits']),
    ]" />

    <div class="table-wrap">
        @if ($payments->isEmpty())
            <x-empty icon="wallet" title="No payments match">
                <a href="{{ route('admin.payments.create') }}" class="btn btn-primary btn-sm">Add balance</a>
            </x-empty>
        @else
            <table class="table">
                <thead>
                    <tr><th>Reference</th><th>Reseller</th><th>By</th><th>Description</th>
                        <th class="num">Added</th><th class="num">Returned</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td class="font-mono text-xs">{{ $payment->trxid }}</td>
                            <td class="text-sm">{{ $payment->reseller?->displayName() ?? $payment->userid }}</td>
                            <td class="text-sm text-subtle">{{ $payment->performedBy?->displayName() ?? '—' }}</td>
                            <td class="max-w-md truncate text-sm">{{ $payment->desc }}</td>
                            <td class="num" style="color: var(--color-emerald-600);">
                                {{ (float) $payment->credit > 0 ? number_format((float) $payment->credit, 2) : '—' }}
                            </td>
                            <td class="num" style="color: var(--color-rose-600);">
                                {{ (float) $payment->debit > 0 ? number_format((float) $payment->debit, 2) : '—' }}
                            </td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $payment->date }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0">
        <a href="{{ route('admin.payments.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="size-4" /> Add balance
        </a>
        {{ $payments->links() }}
    </div>
</div>

@endsection
