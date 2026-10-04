@extends('layouts.panel')

@section('title', 'Providers')
@section('heading', 'Providers')
@section('subheading', 'Upstream services that fulfil recharges')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<div class="grid gap-4 lg:grid-cols-3">

    <div class="space-y-4 lg:col-span-2">
        @forelse ($providers as $provider)
            <form method="POST" action="{{ route('admin.providers.update', $provider) }}" class="card">
                @csrf @method('PUT')

                <div class="card-header">
                    <div>
                        <h2 class="card-title">{{ $provider->api_title }}</h2>
                        <p class="text-xs text-subtle">
                            Balance {{ $money($provider->balance) }}
                            @if ((float) $provider->balance < 1000)
                                <span style="color: var(--color-rose-600);">· low</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="btn btn-primary btn-sm">Save</button>
                    </div>
                </div>

                <div class="card-body grid gap-5 sm:grid-cols-2">
                    <x-setting-field name="api_title" label="Name" :value="$provider->api_title" required />

                    <div>
                        <label for="drv-{{ $provider->id }}" class="label">Driver <span style="color: var(--color-rose-500);">*</span></label>
                        <select id="drv-{{ $provider->id }}" name="provider" class="select" required>
                            @foreach ($drivers as $id => $name)
                                <option value="{{ $id }}" @selected((int) $provider->provider === (int) $id)>{{ $name }} ({{ $id }})</option>
                            @endforeach
                            @if (! array_key_exists((int) $provider->provider, $drivers))
                                <option value="{{ $provider->provider }}" selected>Unknown ({{ $provider->provider }})</option>
                            @endif
                        </select>
                        <p class="field-hint">Decides which integration is used to talk to this provider.</p>
                    </div>

                    <x-setting-field name="url" label="Host or base URL" :value="$provider->url" required />
                    <x-setting-field name="port" label="Port" :value="$provider->port" />
                    <x-setting-field name="userid" label="Access ID" :value="$provider->userid" required />

                    <x-setting-field name="api_key" label="API key" type="password"
                        help="Write-only. Leave blank to keep the current key; the stored value is never displayed." />

                    <x-setting-field name="bal_url" label="Balance endpoint" :value="$provider->bal_url" />
                    <x-setting-field name="resurl" label="Status endpoint" :value="$provider->resurl" />

                    <div class="sm:col-span-2">
                        <x-setting-toggle name="status" label="Accepting traffic" :checked="(int) $provider->status === 1" />
                    </div>
                </div>

                <div class="card-header justify-end border-t border-b-0">
                    <button type="submit" class="btn btn-secondary btn-sm"
                            formaction="{{ route('admin.providers.balance', $provider) }}" formmethod="POST">
                        <x-icon name="refresh" class="size-4" /> Refresh balance
                    </button>
                </div>
            </form>
        @empty
            <div class="card">
                <x-empty icon="server" title="No providers configured"
                         message="Without a provider, every request falls back to manual review." />
            </div>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.providers.store') }}" class="card h-fit">
        @csrf
        <div class="card-header"><h2 class="card-title">Add Provider</h2></div>
        <div class="card-body space-y-4">
            <x-setting-field name="api_title" label="Name" required />

            <div>
                <label for="set-provider" class="label">Driver <span style="color: var(--color-rose-500);">*</span></label>
                <select id="set-provider" name="provider" class="select" required>
                    @foreach ($drivers as $id => $name)
                        <option value="{{ $id }}">{{ $name }} ({{ $id }})</option>
                    @endforeach
                    <option value="0">generic (default)</option>
                </select>
            </div>

            <x-setting-field name="url" label="Host or base URL" required />
            <x-setting-field name="port" label="Port" />
            <x-setting-field name="userid" label="Access ID" required />
            <x-setting-field name="api_key" label="API key" type="password" required />
            <x-setting-toggle name="status" label="Accepting traffic" :checked="true" />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary btn-sm">Add provider</button>
        </div>
    </form>
</div>

@endsection
