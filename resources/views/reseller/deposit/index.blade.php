@extends('layouts.panel')

@section('title', 'Deposit History')
@section('heading', 'Deposit History')
@section('subheading', 'Top-ups you have recorded')

@section('content')

<div class="card">
    <div class="table-wrap">
        @if ($requests->isEmpty())
            <x-empty icon="card" title="No deposits recorded">
                <a href="{{ route('reseller.deposit.create') }}" class="btn btn-primary btn-sm">Add balance</a>
            </x-empty>
        @else
            <table class="table">
                <thead>
                    <tr><th>Transaction ID</th><th>Channel</th><th>From</th><th class="num">Amount</th><th>Status</th><th>When</th></tr>
                </thead>
                <tbody>
                    @foreach ($requests as $claim)
                        <tr>
                            <td class="font-mono text-sm">{{ $claim->trx_id }}</td>
                            <td class="text-sm">{{ $claim->gatway }}</td>
                            <td class="font-mono text-sm">{{ $claim->sender }}</td>
                            <td class="num">{{ number_format((float) $claim->amount, 2) }}</td>
                            <td>
                                @php $tone = ['0' => 'amber', '1' => 'emerald', '2' => 'rose'][(string) $claim->status] ?? 'slate'; @endphp
                                <span class="badge"
                                      style="background-color: color-mix(in oklch, var(--color-{{ $tone }}-500) 15%, transparent); color: var(--color-{{ $tone }}-700);">
                                    {{ ['0' => 'Awaiting verification', '1' => 'Credited', '2' => 'Rejected'][(string) $claim->status] ?? 'Unknown' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $claim->idate }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0">
        <a href="{{ route('reseller.deposit.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="size-4" /> Add balance
        </a>
        {{ $requests->links() }}
    </div>
</div>

@endsection
