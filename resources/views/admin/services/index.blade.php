@extends('layouts.panel')

@section('title', 'Services')
@section('heading', 'Services')
@section('subheading', 'What each service accepts, and when a request needs approval')

@section('content')

<div class="space-y-4">
    @foreach ($services as $service)
        <form method="POST" action="{{ route('admin.services.update', $service) }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">{{ $service->type()?->label() ?? $service->title }}</h2>
                    <p class="text-xs text-subtle">Service code {{ $service->serviceid }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <x-setting-toggle name="status" label="Enabled" :checked="(int) $service->status === 1" />
                    <button class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>

            <div class="card-body grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-setting-field name="title" label="Display name" :value="$service->title" required />

                <x-setting-field name="auto_limit" label="Auto-approve up to" type="number" :unit="$symbol"
                    min="0" :value="$service->auto_limit" required
                    help="At or below this, requests go straight to the provider. Above it they wait for an operator." />

                <x-setting-field name="min_amount" label="Minimum amount" type="number" :unit="$symbol"
                    min="0" :value="$service->min_amount" required />

                <x-setting-field name="max_amount" label="Maximum amount" type="number" :unit="$symbol"
                    min="1" :value="$service->max_amount" required />

                <x-setting-field name="min_length" label="Minimum number length" type="number" unit="digits"
                    min="1" max="30" :value="$service->min_length" required />

                <x-setting-field name="max_length" label="Maximum number length" type="number" unit="digits"
                    min="1" max="40" :value="$service->max_length" required />

                <x-setting-field name="sorder" label="Display order" type="number" min="0" max="99"
                    :value="$service->sorder" required
                    help="Lower numbers appear first in the app." />

                <div class="space-y-3">
                    <x-setting-toggle name="requr_pin" label="Require PIN" :checked="$service->requiresPin()" />
                    <x-setting-toggle name="name" label="Require customer name" :checked="$service->requiresCustomerName()" />
                    <x-setting-toggle name="nid" label="Require national ID" :checked="$service->requiresNid()" />
                    <x-setting-toggle name="sender_number" label="Require sender number" :checked="$service->requiresSenderNumber()" />
                </div>
            </div>
        </form>
    @endforeach
</div>

@endsection
