@extends('layouts.panel')

@section('title', 'Support')
@section('heading', 'Support')
@section('subheading', 'Your conversations with the support team')

@section('content')

<div class="card">
    <div class="table-wrap">
        @if ($tickets->isEmpty())
            <x-empty icon="ticket" title="No tickets yet"
                     message="Open a ticket if something needs attention.">
                <a href="{{ route('reseller.tickets.create') }}" class="btn btn-primary btn-sm">Open a ticket</a>
            </x-empty>
        @else
            <table class="table">
                <thead><tr><th>Subject</th><th>Status</th><th>Opened</th></tr></thead>
                <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td>
                                <a href="{{ route('reseller.tickets.show', $ticket) }}" class="font-medium hover:underline">
                                    {{ $ticket->subject }}
                                </a>
                            </td>
                            <td>
                                @if ((int) $ticket->status === 1)
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-emerald-500) 15%, transparent); color: var(--color-emerald-700);">Answered</span>
                                @else
                                    <span class="badge" style="background-color: color-mix(in oklch, var(--color-amber-500) 15%, transparent); color: var(--color-amber-700);">Open</span>
                                @endif
                            </td>
                            <td class="text-xs text-subtle">
                                {{ $ticket->c_date ? \Carbon\Carbon::createFromTimestamp((int) $ticket->c_date)->format('d M Y') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card-header border-t border-b-0">
        <a href="{{ route('reseller.tickets.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="size-4" /> Open a ticket
        </a>
        {{ $tickets->links() }}
    </div>
</div>

@endsection
