@extends('layouts.panel')

@section('title', 'New Recharge')
@section('heading', 'New Recharge')
@section('subheading', 'Pick a service, confirm the price, then authorise with your PIN')

@section('content')

<div
    class="grid gap-4 lg:grid-cols-3"
    x-data="rechargeForm({
        services: @js($services),
        quoteUrl: @js(route('reseller.recharge.lookup.quote')),
        operatorUrl: @js(route('reseller.recharge.lookup.operator')),
        packagesUrl: @js(route('reseller.recharge.lookup.packages')),
        symbol: @js($symbol),
        old: {
            service: @js(old('service')),
            number: @js(old('number')),
            amount: @js(old('amount')),
            type: @js(old('type', '1')),
        }
    })"
>

    {{-- Form ------------------------------------------------------------- --}}

    <form method="POST" action="{{ route('reseller.recharge.store') }}" class="lg:col-span-2 space-y-4"
          @submit="submitting = true">
        @csrf

        {{-- Step 1: service --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <span class="mr-1.5 text-subtle">1.</span> Choose a service
                </h2>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="svc in services" :key="svc.id">
                        <button type="button"
                                @click="selectService(svc)"
                                class="flex flex-col items-center gap-1.5 rounded-lg border p-3 transition"
                                :class="service?.id === svc.id
                                    ? 'border-transparent ring-2 ring-brand-500'
                                    : 'hover:border-current'"
                                :style="service?.id === svc.id
                                    ? 'background-color: color-mix(in oklch, var(--color-brand-500) 10%, transparent); border-color: var(--color-brand-500);'
                                    : 'border-color: var(--line); background-color: var(--panel);'"
                                :aria-pressed="service?.id === svc.id">
                            <span class="grid size-8 place-items-center rounded-lg"
                                  style="background-color: var(--panel-muted);">
                                {{-- Icon chosen per service by the enum --}}
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <rect x="6" y="2" width="12" height="20" rx="2" x-show="svc.icon === 'phone'"/>
                                    <path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5.5 5.5 0 0 1 7 0M12 19.5h.01" x-show="svc.icon === 'wifi'"/>
                                    <path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" x-show="svc.icon === 'wallet'"/>
                                    <path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z" x-show="svc.icon === 'receipt'"/>
                                    <path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" x-show="svc.icon === 'bank'"/>
                                    <path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 9 9 0 0 1-3.9-.9L3 21l1.9-5.1A8.4 8.4 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z" x-show="svc.icon === 'chat'"/>
                                    <rect x="2" y="5" width="20" height="14" rx="2" x-show="svc.icon === 'card'"/>
                                    <template x-if="!['phone','wifi','wallet','receipt','bank','chat','card'].includes(svc.icon)">
                                        <g><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></g>
                                    </template>
                                </svg>
                            </span>
                            <span class="text-center text-xs font-medium leading-tight" x-text="svc.label"></span>
                        </button>
                    </template>
                </div>

                <input type="hidden" name="service" :value="service?.id ?? ''">

                <template x-if="service">
                    <p class="field-hint" x-text="`Amount between ${service.min} and ${service.max}. Requests above ${service.auto_limit} are reviewed before dispatch.`"></p>
                </template>
            </div>
        </div>

        {{-- Step 2: destination and amount --}}
        <div class="card" x-show="service" x-cloak>
            <div class="card-header">
                <h2 class="card-title">
                    <span class="mr-1.5 text-subtle">2.</span> Destination &amp; amount
                </h2>
            </div>
            <div class="card-body space-y-4">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="number" class="label">Mobile number</label>
                        <div class="relative">
                            <input id="number" name="number" type="tel" inputmode="numeric"
                                   class="input pr-20" x-model="number"
                                   @input.debounce.400ms="lookupOperator()"
                                   placeholder="01XXXXXXXXX" required autocomplete="off">
                            <span class="absolute inset-y-0 right-0 flex items-center pr-3">
                                <span x-show="operator" x-cloak class="badge"
                                      style="background-color: color-mix(in oklch, var(--color-brand-500) 15%, transparent); color: var(--color-brand-700);"
                                      x-text="operator"></span>
                            </span>
                        </div>
                        <p class="field-error" x-show="operatorError" x-cloak x-text="operatorError"></p>
                        <input type="hidden" name="operator" :value="operator ?? ''">
                    </div>

                    <div>
                        <label for="type" class="label">Connection type</label>
                        <select id="type" name="type" class="select" x-model="type" @change="refreshQuote()">
                            <option value="1">Prepaid</option>
                            <option value="2">Postpaid</option>
                        </select>
                    </div>
                </div>

                {{-- Packages, when the service offers them --}}
                <div x-show="service?.has_packages && packages.length" x-cloak>
                    <label class="label">Package (optional)</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <template x-for="pack in packages" :key="pack.id">
                            <button type="button" @click="selectPackage(pack)"
                                    class="flex items-center justify-between gap-2 rounded-lg border p-2.5 text-left"
                                    :style="packageId === pack.id
                                        ? 'border-color: var(--color-brand-500); background-color: color-mix(in oklch, var(--color-brand-500) 8%, transparent);'
                                        : 'border-color: var(--line);'">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium" x-text="pack.name"></span>
                                    <span class="block text-xs text-subtle" x-text="pack.volume"></span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold tnum" x-text="`${symbol} ${pack.payable}`"></span>
                            </button>
                        </template>
                    </div>
                    <input type="hidden" name="package" :value="packageId ?? ''">
                    <button type="button" class="btn btn-ghost btn-sm mt-2" x-show="packageId" x-cloak
                            @click="clearPackage()">Clear package</button>
                </div>

                <div>
                    <label for="amount" class="label">Amount</label>
                    <input id="amount" name="amount" type="number" step="1" min="1"
                           class="input text-lg font-semibold tnum" x-model="amount"
                           @input.debounce.400ms="refreshQuote()"
                           :readonly="packageId !== null" required>

                    <div class="mt-2 flex flex-wrap gap-1.5" x-show="packageId === null">
                        @foreach ($quickAmounts as $value)
                            <button type="button" class="btn btn-secondary btn-sm"
                                    @click="amount = {{ $value }}; refreshQuote()">
                                {{ $symbol }} {{ $value }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Conditional fields driven by the service configuration --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div x-show="service?.needs_name" x-cloak>
                        <label for="name" class="label">Customer name</label>
                        <input id="name" name="name" type="text" class="input" value="{{ old('name') }}">
                    </div>
                    <div x-show="service?.needs_nid" x-cloak>
                        <label for="nid" class="label">National ID</label>
                        <input id="nid" name="nid" type="text" class="input" value="{{ old('nid') }}">
                    </div>
                    <div x-show="service?.needs_sender" x-cloak>
                        <label for="sender" class="label">Sender number</label>
                        <input id="sender" name="sender" type="tel" class="input" value="{{ old('sender') }}">
                    </div>
                    <div>
                        <label for="remark" class="label">Note (optional)</label>
                        <input id="remark" name="remark" type="text" class="input" maxlength="160"
                               value="{{ old('remark') }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 3: authorise --}}
        <div class="card" x-show="service" x-cloak>
            <div class="card-header">
                <h2 class="card-title">
                    <span class="mr-1.5 text-subtle">3.</span> Authorise
                </h2>
            </div>
            <div class="card-body space-y-4">
                <div x-show="service?.needs_pin">
                    <label for="pin" class="label">Transaction PIN</label>
                    <input id="pin" name="pin" type="password" inputmode="numeric"
                           class="input max-w-40 tracking-[0.4em]" autocomplete="off"
                           :required="service?.needs_pin">
                    <p class="field-hint">Your PIN is never stored or logged.</p>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-full sm:w-auto"
                        :disabled="submitting || !canSubmit">
                    <span x-show="!submitting">
                        Confirm &amp; send
                        <template x-if="quote?.payable">
                            <span x-text="` — ${symbol} ${quote.payable}`"></span>
                        </template>
                    </span>
                    <span x-show="submitting" x-cloak>Sending&hellip;</span>
                </button>

                <p class="field-error" x-show="quote && !quote.affordable" x-cloak>
                    Your balance is not enough for this request.
                </p>
            </div>
        </div>
    </form>

    {{-- Live summary ----------------------------------------------------- --}}

    <div class="space-y-4">
        <div class="card sticky top-20">
            <div class="card-header">
                <h2 class="card-title">Summary</h2>
                <span x-show="loadingQuote" x-cloak class="text-xs text-subtle">updating&hellip;</span>
            </div>
            <div class="card-body">
                <template x-if="!quote">
                    <p class="text-sm text-subtle">
                        Enter a number and amount to see exactly what you will be charged.
                    </p>
                </template>

                <template x-if="quote">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-muted">Operator</dt>
                            <dd class="font-medium" x-text="quote.operator ?? '—'"></dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-muted">Customer receives</dt>
                            <dd class="font-medium tnum" x-text="`${symbol} ${quote.face.toFixed(2)}`"></dd>
                        </div>
                        <div class="flex items-center justify-between" x-show="quote.commission > 0">
                            <dt class="text-muted">Your commission</dt>
                            <dd class="font-medium tnum" style="color: var(--color-emerald-600);"
                                x-text="`− ${symbol} ${quote.commission.toFixed(2)}`"></dd>
                        </div>
                        <div class="flex items-center justify-between" x-show="quote.charge > 0">
                            <dt class="text-muted">Service charge</dt>
                            <dd class="font-medium tnum" x-text="`+ ${symbol} ${quote.charge.toFixed(2)}`"></dd>
                        </div>

                        <div class="border-t pt-2.5" style="border-color: var(--line);">
                            <div class="flex items-baseline justify-between">
                                <dt class="font-medium">You pay</dt>
                                <dd class="text-xl font-bold tnum"
                                    x-text="`${symbol} ${quote.payable.toFixed(2)}`"></dd>
                            </div>
                            <p class="mt-1 text-xs text-subtle"
                               x-text="`From ${quote.wallet} · ${symbol} ${quote.available.toFixed(2)} available`"></p>
                        </div>

                        <p x-show="!quote.resolved" x-cloak class="field-error" x-text="quote.message"></p>
                    </dl>
                </template>
            </div>
        </div>

        @if ($recent->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent</h2>
                    <a href="{{ route('reseller.recharge.index') }}" class="btn btn-ghost btn-sm">All</a>
                </div>
                <div class="card-body space-y-2.5">
                    @foreach ($recent as $item)
                        <a href="{{ route('reseller.recharge.show', $item) }}"
                           class="flex items-center justify-between gap-2 rounded-lg p-2 hover:bg-[var(--panel-muted)]">
                            <span class="min-w-0">
                                <span class="block truncate font-mono text-sm">{{ $item->phone }}</span>
                                <span class="block text-xs text-subtle">
                                    {{ $item->serviceLabel() }} · {{ $item->submitted_date?->diffForHumans(short: true) }}
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block text-sm font-semibold tnum">
                                    {{ number_format((float) $item->balance) }}
                                </span>
                                <x-status-badge :status="$item->status" />
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Behaviour lives in resources/js/app.js (Alpine.data rechargeForm) --}}

@endsection
