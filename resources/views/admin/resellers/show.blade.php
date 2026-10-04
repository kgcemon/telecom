@extends('layouts.panel')

@section('title', $reseller->displayName())
@section('heading', $reseller->displayName())
@section('subheading', $reseller->username . ' · ' . ($reseller->level()?->defaultLabel() ?? ''))

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat label="Main Balance" :value="$money($reseller->balance)" icon="wallet" tone="brand" />
    <x-stat label="Bank Balance" :value="$money($reseller->bank_balance)" icon="bank" tone="sky" />
    <x-stat label="Volume This Month" :value="$money($activity['volume'])"
            :meta="number_format($activity['count']) . ' requests'" icon="chart" tone="emerald" />
    <x-stat label="Credit Limit" :value="$money($reseller->balance_limit)"
            meta="Floor the main wallet may not go below" icon="shield" tone="violet" />
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Account</h2>
            <a href="{{ route('admin.resellers.edit', $reseller) }}" class="btn btn-secondary btn-sm">Edit</a>
        </div>
        <div class="card-body">
            <dl class="space-y-3 text-sm">
                @foreach ([
                    'Username' => $reseller->username,
                    'Mobile' => $reseller->mobile ?: '—',
                    'Email' => $reseller->email ?: '—',
                    'Upline' => $reseller->parent?->displayName() ?? '—',
                    'Tariff' => $reseller->tariff?->name ?? '—',
                    'Created' => $reseller->create_date ?: '—',
                    'Last sign-in' => $reseller->last_login ?: 'Never',
                    'Sign-ins' => $reseller->login_count,
                ] as $label => $value)
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-muted">{{ $label }}</dt>
                        <dd class="text-right font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Recent Requests</h2></div>
        <div class="table-wrap">
            @if ($recent->isEmpty())
                <x-empty icon="list" title="No requests yet" />
            @else
                <table class="table">
                    <thead>
                        <tr><th>Reference</th><th>Service</th><th>Number</th><th class="num">Amount</th><th>Status</th><th>When</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $item)
                            <tr>
                                <td><a href="{{ route('admin.requests.show', $item) }}" class="font-mono text-xs hover:underline">{{ Str::limit($item->sid, 12, '') }}</a></td>
                                <td class="text-sm">{{ $item->serviceLabel() }}</td>
                                <td class="font-mono text-sm">{{ $item->phone }}</td>
                                <td class="num">{{ number_format((float) $item->balance) }}</td>
                                <td><x-status-badge :status="$item->status" /></td>
                                <td class="text-xs text-subtle">{{ $item->submitted_date?->diffForHumans(short: true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

{{-- Permissions ------------------------------------------------------- --}}

<form method="POST" action="{{ route('admin.resellers.permissions', $reseller) }}" class="card mt-4">
    @csrf
    <div class="card-header">
        <div>
            <h2 class="card-title">Permissions</h2>
            <p class="text-xs text-subtle">
                These control what this account can actually do, not just what it can see.
            </p>
        </div>
        <button class="btn btn-primary btn-sm">Save permissions</button>
    </div>
    <div class="card-body grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($groups as $group => $items)
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-subtle">{{ $group }}</p>
                <div class="space-y-1.5">
                    @foreach ($items as $key => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                   class="size-4 rounded" @checked(! empty($permissions[$key]))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</form>

@if ($children->isNotEmpty())
    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">Accounts Beneath This One</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Account</th><th>Level</th><th class="num">Balance</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($children as $child)
                        <tr>
                            <td><a href="{{ route('admin.resellers.show', $child) }}" class="font-medium hover:underline">{{ $child->displayName() }}</a></td>
                            <td class="text-sm">{{ $child->level()?->defaultLabel() }}</td>
                            <td class="num">{{ number_format((float) $child->balance, 2) }}</td>
                            <td>{{ $child->isActive() ? 'Active' : 'Suspended' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
