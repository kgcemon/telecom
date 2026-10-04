@extends('layouts.panel')

@section('title', 'Add Reseller')
@section('heading', 'Add Reseller')
@section('subheading', 'Create an account beneath yours')

@section('content')

<form method="POST" action="{{ route('admin.resellers.store') }}" class="card max-w-3xl">
    @csrf

    <div class="card-header"><h2 class="card-title">Account Details</h2></div>

    <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-setting-field name="username" label="Username" required
            help="Letters, numbers, dot, dash and underscore only. Cannot be changed later." />

        <div>
            <label for="set-custype" class="label">Level <span style="color: var(--color-rose-500);">*</span></label>
            <select id="set-custype" name="custype" class="select" required>
                @foreach ($levels as $value => $label)
                    <option value="{{ $value }}" @selected(old('custype') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="field-hint">Decides what this account may do and who it can create beneath it.</p>
        </div>

        <x-setting-field name="name" label="Full name" required />
        <x-setting-field name="mobile" label="Mobile" required
            help="Used for OTP delivery and for support to reach them." />
        <x-setting-field name="email" label="Email" type="email" />

        <div>
            <label for="set-tarif" class="label">Tariff</label>
            <select id="set-tarif" name="tarif" class="select">
                <option value="">Create a new tariff from mine</option>
                @foreach ($tariffs as $tariff)
                    <option value="{{ $tariff->id }}" @selected((string) old('tarif') === (string) $tariff->id)>{{ $tariff->name }}</option>
                @endforeach
            </select>
            <p class="field-hint">Leave blank to copy your own prices into a new tariff for them.</p>
        </div>

        <x-setting-field name="balance_limit" label="Credit limit" type="number" :unit="$symbol"
            min="0" value="0"
            help="How far below zero their main wallet may go. Leave at 0 for no credit." />

        <x-setting-field name="password" label="Password" type="password" required
            help="At least 8 characters. Share it securely; it is not shown again." />
        <x-setting-field name="password_confirmation" label="Confirm password" type="password" required />

        <x-setting-field name="pincode" label="Transaction PIN" type="password" required
            help="Digits only. Required to authorise recharges and transfers." />

        <x-setting-field name="note" label="Note" class="sm:col-span-2" />

        <div class="space-y-3 sm:col-span-2">
            <x-setting-toggle name="webAccess" label="Allow web panel sign-in" :checked="true" />
            <x-setting-toggle name="appsAccess" label="Allow mobile app sign-in" :checked="true" />
        </div>
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('admin.resellers.index') }}" class="btn btn-ghost">Cancel</a>
        <button class="btn btn-primary">Create reseller</button>
    </div>
</form>

@endsection
