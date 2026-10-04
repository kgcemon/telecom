@extends('layouts.panel')

@section('title', 'Open a Ticket')
@section('heading', 'Open a Ticket')

@section('content')

<form method="POST" action="{{ route('reseller.tickets.store') }}" class="card max-w-2xl">
    @csrf
    <div class="card-header"><h2 class="card-title">New Ticket</h2></div>
    <div class="card-body space-y-4">
        <x-setting-field name="subject" label="Subject" required
            help="A short summary, for example: recharge not delivered." />

        <div>
            <label for="message" class="label">Message <span style="color: var(--color-rose-500);">*</span></label>
            <textarea id="message" name="message" class="textarea" rows="6" required maxlength="2000">{{ old('message') }}</textarea>
            <p class="field-hint">Include the transaction reference if your question is about a specific request.</p>
        </div>
    </div>
    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('reseller.tickets.index') }}" class="btn btn-ghost">Cancel</a>
        <button class="btn btn-primary">Send</button>
    </div>
</form>

@endsection
