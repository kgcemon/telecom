@extends('layouts.panel')

@section('title', 'Manage ' . $reseller->displayName())
@section('heading', 'Manage ' . $reseller->displayName())
@section('subheading', $reseller->username . ' · ' . ($reseller->level()?->defaultLabel() ?? ''))

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="grid gap-4 lg:grid-cols-3">

    <form method="POST" action="{{ route('reseller.downline.update', $reseller) }}" class="card lg:col-span-2">
        @csrf @method('PUT')

        <div class="card-header"><h2 class="card-title">Account</h2></div>

        <div class="card-body grid gap-5 sm:grid-cols-2">
            <x-setting-field name="name" label="Full name" :value="$reseller->name" required />
            <x-setting-field name="mobile" label="Mobile" :value="$reseller->mobile" required />
            <x-setting-field name="email" label="Email" type="email" :value="$reseller->email" />

            <div class="space-y-3 sm:col-span-2 border-t pt-5" style="border-color: var(--line);">
                <x-setting-toggle name="status" label="Account active"
                    help="Switching this off signs them out immediately."
                    :checked="(int) $reseller->status === 1" />
                <x-setting-toggle name="recharge" label="Allow recharging"
                    :checked="(int) $reseller->recharge === 1" />
            </div>

            <div class="sm:col-span-2 border-t pt-5" style="border-color: var(--line);">
                <p class="mb-1 text-sm font-medium">Reset credentials</p>
                <p class="mb-4 text-sm text-subtle">Leave blank to keep what they have now.</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-setting-field name="password" label="New password" type="password" />
                    <x-setting-field name="password_confirmation" label="Confirm password" type="password" />
                    <x-setting-field name="pincode" label="New PIN" type="password" />
                </div>
            </div>
        </div>

        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary">Save changes</button>
        </div>
    </form>

    <div class="space-y-4">
        <x-stat label="Their Balance" :value="$money($reseller->balance)" icon="wallet" tone="brand" />

        <form method="POST" action="{{ route('reseller.downline.balance', $reseller) }}" class="card">
            @csrf
            <div class="card-header"><h2 class="card-title">Transfer Balance</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <label for="set-action" class="label">Direction</label>
                    <select id="set-action" name="action" class="select" required>
                        <option value="add">Give balance to them</option>
                        <option value="return">Take balance back</option>
                    </select>
                </div>

                <x-setting-field name="amount" label="Amount" type="number" :unit="$symbol" required min="0.01" />
                <x-setting-field name="remark" label="Note" />
                <x-setting-field name="pin" label="Your transaction PIN" type="password" required
                    help="Required for every balance movement." />
            </div>
            <div class="card-header justify-end border-t border-b-0">
                <button class="btn btn-primary btn-sm">Transfer</button>
            </div>
        </form>
    </div>
</div>

@endsection
