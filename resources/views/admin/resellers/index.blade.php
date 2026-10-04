@extends('layouts.panel')

@section('title', 'Resellers')
@section('heading', 'Resellers')
@section('subheading', 'Accounts in your distribution network')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('admin.resellers.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-q" class="label">Search</label>
            <input id="f-q" name="q" class="input" value="{{ $filters['q'] ?? '' }}" placeholder="Name, username or mobile">
        </div>
        <div>
            <label for="f-level" class="label">Level</label>
            <select id="f-level" name="level" class="select">
                <option value="">All levels</option>
                @foreach ($levels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['level'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-status" class="label">Status</label>
            <select id="f-status" name="status" class="select">
                <option value="">Any</option>
                <option value="1" @selected(($filters['status'] ?? '') === '1')>Active</option>
                <option value="0" @selected(($filters['status'] ?? '') === '0')>Suspended</option>
            </select>
        </div>
    </x-filter-bar>

    <x-totals-bar :items="[
        'Accounts' => number_format($totals['count']),
        'Main balance held' => $money($totals['main']),
        'Bank balance held' => $money($totals['bank']),
    ]" />

    <div class="table-wrap">
        @if ($resellers->isEmpty())
            <x-empty icon="users" title="No accounts match">
                <a href="{{ route('admin.resellers.create') }}" class="btn btn-primary btn-sm">Add a reseller</a>
            </x-empty>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Account</th><th>Level</th><th>Upline</th><th>Mobile</th>
                        <th class="num">Main</th><th class="num">Bank</th><th>Status</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resellers as $reseller)
                        <tr>
                            <td>
                                <a href="{{ route('admin.resellers.show', $reseller) }}" class="font-medium hover:underline">
                                    {{ $reseller->displayName() }}
                                </a>
                                <p class="text-xs text-subtle">{{ $reseller->username }} · #{{ $reseller->id }}</p>
                            </td>
                            <td class="text-sm">{{ $levels[$reseller->custype] ?? $reseller->custype }}</td>
                            <td class="text-sm text-subtle">{{ $reseller->parent?->displayName() ?? '—' }}</td>
                            <td class="font-mono text-sm">{{ $reseller->mobile }}</td>
                            <td class="num">{{ number_format((float) $reseller->balance, 2) }}</td>
                            <td class="num">{{ number_format((float) $reseller->bank_balance, 2) }}</td>
                            <td>
                                @if ($reseller->isActive())
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-emerald-500) 15%, transparent); color: var(--color-emerald-700);">Active</span>
                                @else
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-rose-500) 15%, transparent); color: var(--color-rose-700);">Suspended</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('admin.resellers.edit', $reseller) }}" class="btn btn-secondary btn-sm">
                                    <x-icon name="edit" class="size-4" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0">
        <a href="{{ route('admin.resellers.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="size-4" /> Add reseller
        </a>
        {{ $resellers->links() }}
    </div>
</div>

@endsection
