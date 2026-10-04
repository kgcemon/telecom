@extends('layouts.panel')

@section('title', 'Access Logs')
@section('heading', 'Access Logs')
@section('subheading', 'Sign-in attempts and privileged actions')

@section('content')

<div class="grid gap-4 lg:grid-cols-2">

    <div class="card">
        <x-filter-bar :action="route('admin.security.logs')" :has-filters="collect($filters)->filter()->isNotEmpty()">
            <div>
                <label for="f-login" class="label">Username</label>
                <input id="f-login" name="login" class="input" value="{{ $filters['login'] ?? '' }}">
            </div>
            <div>
                <label for="f-type" class="label">Result</label>
                <select id="f-type" name="type" class="select">
                    <option value="">All</option>
                    <option value="success" @selected(($filters['type'] ?? '') === 'success')>Successful</option>
                    <option value="failure" @selected(($filters['type'] ?? '') === 'failure')>Failed</option>
                </select>
            </div>
        </x-filter-bar>

        <div class="table-wrap">
            @if ($attempts->isEmpty())
                <x-empty icon="shield" title="No sign-in attempts recorded" />
            @else
                <table class="table">
                    <thead><tr><th>Username</th><th>Result</th><th>Detail</th><th>IP</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($attempts as $attempt)
                            <tr>
                                <td class="text-sm font-medium">{{ $attempt->login }}</td>
                                <td>
                                    @php $ok = $attempt->type === 'success'; @endphp
                                    <span class="badge"
                                          style="background-color: color-mix(in oklch, var(--color-{{ $ok ? 'emerald' : 'rose' }}-500) 15%, transparent); color: var(--color-{{ $ok ? 'emerald' : 'rose' }}-700);">
                                        {{ $ok ? 'Success' : 'Failed' }}
                                    </span>
                                </td>
                                <td class="max-w-40 truncate text-xs text-subtle">{{ $attempt->mesage }}</td>
                                <td class="font-mono text-xs">
                                    {{ @inet_ntop($attempt->ip_address) ?: $attempt->ip_address }}
                                </td>
                                <td class="whitespace-nowrap text-xs text-subtle">{{ $attempt->date }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($attempts->hasPages())
            <div class="card-header border-t border-b-0">{{ $attempts->links() }}</div>
        @endif
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Recent Privileged Actions</h2></div>
        <div class="table-wrap">
            @if ($activity->isEmpty())
                <x-empty icon="list" title="No recorded actions yet" />
            @else
                <table class="table">
                    <thead><tr><th>Event</th><th>Detail</th><th>By</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($activity as $entry)
                            <tr>
                                <td class="whitespace-nowrap text-xs font-medium">{{ $entry->ctype }}</td>
                                <td class="max-w-sm truncate text-sm">{{ $entry->msg }}</td>
                                <td class="text-xs text-subtle">#{{ $entry->userid }}</td>
                                <td class="whitespace-nowrap text-xs text-subtle">{{ $entry->date }} {{ $entry->time }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

@endsection
