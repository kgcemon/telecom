@extends('layouts.panel')

@section('title', 'Edit ' . $reseller->displayName())
@section('heading', 'Edit ' . $reseller->displayName())
@section('subheading', $reseller->username)

@section('content')

<form method="POST" action="{{ route('admin.resellers.update', $reseller) }}" class="card max-w-3xl">
    @csrf @method('PUT')

    <div class="card-header"><h2 class="card-title">Account</h2></div>

    <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-setting-field name="name" label="Full name" :value="$reseller->name" required />
        <x-setting-field name="mobile" label="Mobile" :value="$reseller->mobile" required />
        <x-setting-field name="email" label="Email" type="email" :value="$reseller->email" />

        <div>
            <label for="set-tarif" class="label">Tariff</label>
            <select id="set-tarif" name="tarif" class="select">
                <option value="">Unchanged</option>
                @foreach ($tariffs as $tariff)
                    <option value="{{ $tariff->id }}" @selected((string) $reseller->tarif === (string) $tariff->id)>{{ $tariff->name }}</option>
                @endforeach
            </select>
            <p class="field-hint">Changing this changes what they pay for every service.</p>
        </div>

        <x-setting-field name="balance_limit" label="Credit limit" type="number" :unit="$symbol"
            min="0" :value="$reseller->balance_limit"
            help="How far below zero their main wallet may go." />

        <x-setting-field name="note" label="Note" :value="$reseller->note" />

        <div class="space-y-3 sm:col-span-2 border-t pt-5" style="border-color: var(--line);">
            <x-setting-toggle name="status" label="Account active"
                help="Switching this off signs them out and blocks all activity."
                :checked="(int) $reseller->status === 1" />
            <x-setting-toggle name="login_allow" label="Allow sign-in" :checked="(int) $reseller->login_allow === 1" />
            <x-setting-toggle name="recharge" label="Allow recharging"
                help="Off keeps the account usable for reports but blocks new transactions."
                :checked="(int) $reseller->recharge === 1" />
            <x-setting-toggle name="webAccess" label="Allow web panel" :checked="(int) $reseller->webAccess === 1" />
            <x-setting-toggle name="appsAccess" label="Allow mobile app" :checked="(int) $reseller->appsAccess === 1" />
            <x-setting-toggle name="device_lock" label="Lock to known devices"
                help="New devices must be verified with the PIN before they can sign in."
                :checked="(int) $reseller->device_lock === 1" />
            <x-setting-toggle name="api" label="Allow machine API access" :checked="(int) $reseller->api === 1" />
        </div>

        <div class="sm:col-span-2 border-t pt-5" style="border-color: var(--line);">
            <p class="mb-3 text-sm font-medium">Reset credentials</p>
            <p class="mb-4 text-sm text-subtle">Leave blank to keep the current password and PIN.</p>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-setting-field name="password" label="New password" type="password" />
                <x-setting-field name="password_confirmation" label="Confirm new password" type="password" />
                <x-setting-field name="pincode" label="New transaction PIN" type="password" />
            </div>
        </div>
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('admin.resellers.show', $reseller) }}" class="btn btn-ghost">Cancel</a>
        <button class="btn btn-primary">Save changes</button>
    </div>
</form>

@endsection
