@extends('layouts.panel')

@section('title', 'Online Now')
@section('heading', 'Online Now')
@section('subheading', 'Accounts active in the last five minutes')

@section('content')

<div class="card">
    <div class="table-wrap">
        @if ($users->isEmpty())
            <x-empty icon="eye" title="Nobody is online right now" />
        @else
            <table class="table">
                <thead><tr><th>Account</th><th>Page</th><th>IP</th><th>Last activity</th></tr></thead>
                <tbody>
                    @foreach ($users as $online)
                        <tr>
                            <td class="text-sm font-medium">#{{ $online->userid }}</td>
                            <td class="max-w-sm truncate text-sm text-subtle">{{ $online->page ?: '—' }}</td>
                            <td class="font-mono text-xs">{{ $online->ip_address }}</td>
                            <td class="whitespace-nowrap text-xs text-subtle">
                                {{ $online->time ? \Carbon\Carbon::createFromTimestamp((int) $online->time)->diffForHumans(short: true) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@endsection
