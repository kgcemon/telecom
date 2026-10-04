@extends('layouts.panel')

@section('title', 'Devices')
@section('heading', 'Devices')
@section('subheading', 'Every device that has signed in, and how to revoke it')

@section('content')

<div class="card">
    <x-filter-bar :action="route('admin.security.devices')" :has-filters="collect($filters)->filter()->isNotEmpty()">
        <div>
            <label for="f-reseller" class="label">Reseller ID</label>
            <input id="f-reseller" name="reseller" class="input w-36" value="{{ $filters['reseller'] ?? '' }}">
        </div>
    </x-filter-bar>

    <div class="table-wrap">
        @if ($devices->isEmpty())
            <x-empty icon="device" title="No devices recorded" />
        @else
            <table class="table">
                <thead>
                    <tr><th>Account</th><th>Platform</th><th>Browser / App</th><th>IP</th><th>Last seen</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($devices as $device)
                        <tr>
                            <td class="text-sm">#{{ $device->userid }}</td>
                            <td class="text-sm">{{ $device->os ?: '—' }}</td>
                            <td class="max-w-xs truncate text-xs text-subtle">{{ $device->browser ?: '—' }}</td>
                            <td class="font-mono text-xs">{{ $device->ip }}</td>
                            <td class="whitespace-nowrap text-xs text-subtle">{{ $device->date }} {{ $device->time }}</td>
                            <td>
                                @if ((int) $device->status === 1)
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-emerald-500) 15%, transparent); color: var(--color-emerald-700);">Active</span>
                                @else
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-slate-500) 15%, transparent); color: var(--ink-muted);">Revoked</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ((int) $device->status === 1)
                                    <form method="POST" action="{{ route('admin.security.devices.revoke', $device) }}">
                                        @csrf
                                        <button class="btn btn-danger btn-sm"
                                                onclick="return confirm('Revoke this device? The user will have to sign in again.')">
                                            Revoke
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($devices->hasPages())
        <div class="card-header border-t border-b-0">{{ $devices->links() }}</div>
    @endif
</div>

@endsection
