@extends('layouts.panel')

@section('title', 'Deposit Claims')
@section('heading', 'Deposit Claims')
@section('subheading', 'Resellers reporting money they have sent in')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('admin.payments.requests')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-status" class="label">Status</label>
            <select id="f-status" name="status" class="select">
                <option value="">All</option>
                <option value="0" @selected(($filters['status'] ?? '') === '0')>Pending</option>
                <option value="1" @selected(($filters['status'] ?? '') === '1')>Approved</option>
                <option value="2" @selected(($filters['status'] ?? '') === '2')>Rejected</option>
            </select>
        </div>
    </x-filter-bar>

    <div class="table-wrap">
        @if ($requests->isEmpty())
            <x-empty icon="card" title="No deposit claims" />
        @else
            <table class="table">
                <thead>
                    <tr><th>Transaction ID</th><th>Reseller</th><th>Channel</th><th>Sender</th>
                        <th class="num">Amount</th><th>Status</th><th>When</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($requests as $claim)
                        <tr>
                            <td class="font-mono text-sm">{{ $claim->trx_id }}</td>
                            <td class="text-sm">#{{ $claim->userid }} {{ $claim->sender_name }}</td>
                            <td class="text-sm">{{ $claim->gatway }}</td>
                            <td class="font-mono text-sm">{{ $claim->sender }}</td>
                            <td class="num">{{ number_format((float) $claim->amount, 2) }}</td>
                            <td>
                                @php $tone = ['0' => 'amber', '1' => 'emerald', '2' => 'rose'][(string) $claim->status] ?? 'slate'; @endphp
                                <span class="badge"
                                      style="background-color: color-mix(in oklch, var(--color-{{ $tone }}-500) 15%, transparent); color: var(--color-{{ $tone }}-700);">
                                    {{ ['0' => 'Pending', '1' => 'Approved', '2' => 'Rejected'][(string) $claim->status] ?? 'Unknown' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $claim->idate }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ((int) $claim->status === 0)
                                    <form method="POST" action="{{ route('admin.payments.requests.approve', $claim) }}" class="inline">
                                        @csrf
                                        <button class="btn btn-primary btn-sm"
                                                onclick="return confirm('Approve this deposit and credit the balance?')">
                                            <x-icon name="check" class="size-4" />
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.payments.requests.reject', $claim) }}" class="inline">
                                        @csrf
                                        <button class="btn btn-danger btn-sm">
                                            <x-icon name="x" class="size-4" />
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-subtle">—</span>
                                @endif
                            </td>
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
