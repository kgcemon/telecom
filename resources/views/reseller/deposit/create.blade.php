@extends('layouts.panel')

@section('title', 'Add Balance')
@section('heading', 'Add Balance')
@section('subheading', 'Send money to one of the numbers below, then record it here')

@section('content')

<div class="grid gap-4 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Where to send</h2></div>
        <div class="card-body">
            @if ($gateways->isEmpty())
                <x-empty icon="card" title="No deposit channels available"
                         message="Please contact your upline to arrange a top-up." />
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($gateways as $gateway)
                        <div class="rounded-lg border p-4" style="border-color: var(--line);">
                            <p class="font-medium">{{ $gateway->name }}</p>
                            <p class="mt-1 font-mono text-lg font-semibold tracking-wide">{{ $gateway->number }}</p>
                            @if ($gateway->note)
                                <p class="mt-1.5 text-sm text-subtle">{{ $gateway->note }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 rounded-lg p-3 text-sm"
                     style="background-color: color-mix(in oklch, var(--color-amber-500) 10%, transparent);">
                    <p class="flex items-start gap-2 text-muted">
                        <x-icon name="alert" class="mt-0.5 size-4 shrink-0" style="color: var(--color-amber-600);" />
                        <span>
                            Send the money first, then record the transaction ID below. Your balance is credited
                            only after an operator has verified it against the receiving account.
                        </span>
                    </p>
                </div>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('reseller.deposit.store') }}" class="card h-fit">
        @csrf
        <div class="card-header"><h2 class="card-title">Record your deposit</h2></div>
        <div class="card-body space-y-4">
            <div>
                <label for="set-gateway" class="label">Channel <span style="color: var(--color-rose-500);">*</span></label>
                <select id="set-gateway" name="gateway" class="select" required>
                    @foreach ($gateways as $gateway)
                        <option value="{{ $gateway->id }}">{{ $gateway->name }} — {{ $gateway->number }}</option>
                    @endforeach
                </select>
            </div>

            <x-setting-field name="trx_id" label="Transaction ID" required
                help="Exactly as it appears in your confirmation message." />

            <x-setting-field name="amount" label="Amount sent" type="number" :unit="$symbol" required min="1" />

            <x-setting-field name="sender" label="Your sending number" required />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary">Submit for verification</button>
        </div>
    </form>
</div>

@endsection
