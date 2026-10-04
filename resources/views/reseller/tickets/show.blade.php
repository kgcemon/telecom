@extends('layouts.panel')

@section('title', $ticket->subject)
@section('heading', $ticket->subject)

@section('content')

<div class="card max-w-3xl">
    <div class="card-header">
        <h2 class="card-title">Conversation</h2>
        <span class="badge"
              style="background-color: color-mix(in oklch, var(--color-{{ (int) $ticket->status === 1 ? 'emerald' : 'amber' }}-500) 15%, transparent); color: var(--color-{{ (int) $ticket->status === 1 ? 'emerald' : 'amber' }}-700);">
            {{ (int) $ticket->status === 1 ? 'Answered' : 'Open' }}
        </span>
    </div>

    <div class="card-body space-y-4">
        @foreach ($messages as $message)
            @php $mine = (int) $message->id_user === (int) auth()->guard('reseller')->id(); @endphp
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-lg rounded-lg px-3.5 py-2.5"
                     style="background-color: {{ $mine ? 'color-mix(in oklch, var(--color-brand-500) 12%, transparent)' : 'var(--panel-muted)' }};">
                    <p class="whitespace-pre-line text-sm">{{ $message->msg }}</p>
                    <p class="mt-1 text-xs text-subtle">
                        {{ $mine ? 'You' : 'Support' }} · {{ $message->date }} {{ $message->time }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('reseller.tickets.reply', $ticket) }}" class="card-header border-t border-b-0 flex-col items-stretch gap-3">
        @csrf
        <textarea name="message" class="textarea" rows="3" required maxlength="2000"
                  placeholder="Write a reply..."></textarea>
        <div class="flex justify-end">
            <button class="btn btn-primary btn-sm"><x-icon name="send" class="size-4" /> Reply</button>
        </div>
    </form>
</div>

@endsection
