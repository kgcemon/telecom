@extends('layouts.panel')

@section('title', 'My Resellers')
@section('heading', 'My Resellers')
@section('subheading', 'Accounts you have created')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="card">
    <x-filter-bar :action="route('reseller.downline.index')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-q" class="label">Search</label>
            <input id="f-q" name="q" class="input" value="{{ $filters['q'] ?? '' }}" placeholder="Name, username or mobile">
        </div>
    </x-filter-bar>

    <div class="table-wrap">
        @if ($resellers->isEmpty())
            <x-empty icon="users" title="No accounts yet"
                     message="Create accounts beneath you to grow your network.">
                @if ($user->level()?->childLevel())
                    <a href="{{ route('reseller.downline.create') }}" class="btn btn-primary btn-sm">Add an account</a>
                @endif
            </x-empty>
        @else
            <table class="table">
                <thead>
                    <tr><th>Account</th><th>Level</th><th>Mobile</th><th class="num">Balance</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($resellers as $child)
                        <tr>
                            <td>
                                <p class="font-medium">{{ $child->displayName() }}</p>
                                <p class="text-xs text-subtle">{{ $child->username }}</p>
                            </td>
                            <td class="text-sm">{{ $child->level()?->defaultLabel() }}</td>
                            <td class="font-mono text-sm">{{ $child->mobile }}</td>
                            <td class="num">{{ number_format((float) $child->balance, 2) }}</td>
                            <td>{{ $child->isActive() ? 'Active' : 'Suspended' }}</td>
                            <td class="text-right">
                                <a href="{{ route('reseller.downline.edit', $child) }}" class="btn btn-secondary btn-sm">
                                    <x-icon name="edit" class="size-4" /> Manage
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0">
        @if ($user->level()?->childLevel())
            <a href="{{ route('reseller.downline.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="size-4" /> Add account
            </a>
        @else
            <span class="text-sm text-subtle">Your level cannot create sub-accounts.</span>
        @endif
        {{ $resellers->links() }}
    </div>
</div>

@endsection
