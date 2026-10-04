@extends('layouts.panel')

@section('title', 'Add Balance')
@section('heading', 'Add or Return Balance')
@section('subheading', 'Moves balance between your account and a reseller')

@section('content')

<form method="POST" action="{{ route('admin.payments.store') }}" class="card max-w-2xl">
    @csrf

    <div class="card-header"><h2 class="card-title">Transfer</h2></div>

    <div class="card-body space-y-5">
        <div class="rounded-lg p-3 text-sm"
             style="background-color: color-mix(in oklch, var(--color-sky-500) 8%, transparent);">
            <p class="flex items-start gap-2 text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" style="color: var(--color-sky-600);" />
                <span>
                    Adding balance moves it out of your own wallet into theirs; returning moves it back.
                    Both sides are written to the ledger in one transaction, so the books always balance.
                </span>
            </p>
        </div>

        <x-setting-field name="reseller" label="Reseller ID" type="number" required
            help="The numeric account id, shown on the reseller list." />

        <div>
            <label for="set-action" class="label">Direction <span style="color: var(--color-rose-500);">*</span></label>
            <select id="set-action" name="action" class="select" required>
                <option value="add">Add balance to the reseller</option>
                <option value="return">Return balance from the reseller</option>
            </select>
        </div>

        <div>
            <label for="set-wallet" class="label">Wallet <span style="color: var(--color-rose-500);">*</span></label>
            <select id="set-wallet" name="wallet" class="select" required>
                @foreach ($wallets as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <p class="field-hint">Only the wallets this platform uses are listed.</p>
        </div>

        <x-setting-field name="amount" label="Amount" type="number" :unit="$symbol" required min="0.01" />
        <x-setting-field name="remark" label="Note" help="Appears on both statements." />
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('admin.payments.index') }}" class="btn btn-ghost">Cancel</a>
        <button class="btn btn-primary">Apply transfer</button>
    </div>
</form>

@endsection
