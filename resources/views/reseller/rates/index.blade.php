@extends('layouts.panel')

@section('title', 'My Rates')
@section('heading', 'My Rates')
@section('subheading', 'What you pay, shown on a ' . $symbol . ' ' . number_format($reference) . ' recharge')

@section('content')

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Rate Card</h2>
            <p class="text-xs text-subtle">
                These figures come from the same pricing engine that charges you, so they always match your bill.
            </p>
        </div>
    </div>

    <div class="table-wrap">
        @if (empty($rows))
            <x-empty icon="tag" title="No rates configured"
                     message="Please ask your upline to set your pricing." />
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Service</th>
                        @foreach ($operators as $code)
                            <th class="num">{{ $code }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $service => $byOperator)
                        <tr>
                            <td class="font-medium">{{ $service }}</td>
                            @foreach ($operators as $code)
                                <td class="num">
                                    @if (isset($byOperator[$code]))
                                        <span class="font-medium">{{ number_format($byOperator[$code]['payable_on_100'], 2) }}</span>
                                        @if ($byOperator[$code]['commission_percent'] > 0)
                                            <span class="block text-xs" style="color: var(--color-emerald-600);">
                                                {{ rtrim(rtrim(number_format($byOperator[$code]['commission_percent'], 2), '0'), '.') }}% off
                                            </span>
                                        @elseif ($byOperator[$code]['charge_percent'] > 0)
                                            <span class="block text-xs" style="color: var(--color-rose-600);">
                                                +{{ rtrim(rtrim(number_format($byOperator[$code]['charge_percent'], 2), '0'), '.') }}%
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-subtle">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@endsection
