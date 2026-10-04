@extends('layouts.panel')

@section('title', 'Pending Queue')
@section('heading', 'Pending Queue')
@section('subheading', 'Requests waiting for a decision or still with a provider')

@section('content')

@php $money = fn ($v) => $symbol . ' ' . number_format((float) $v, 2); @endphp

<form method="POST" action="{{ route('admin.requests.bulk') }}" x-data="{ selected: [], action: 'approve' }">
    @csrf

    <div class="card">
        <x-filter-bar :action="route('admin.requests.pending')" :has-filters="collect($filters)->filter()->isNotEmpty()">
            <div>
                <label for="f-service" class="label">Service</label>
                <select id="f-service" name="service" class="select">
                    <option value="">All</option>
                    @foreach ($services as $id => $label)
                        <option value="{{ $id }}" @selected((string) $filters['service'] === (string) $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-q" class="label">Search</label>
                <input id="f-q" name="q" class="input" value="{{ $filters['q'] }}" placeholder="Number or reference">
            </div>
            <div>
                <label for="f-from" class="label">From</label>
                <input id="f-from" name="from" type="date" class="input" value="{{ $filters['from'] }}">
            </div>
            <div>
                <label for="f-to" class="label">To</label>
                <input id="f-to" name="to" type="date" class="input" value="{{ $filters['to'] }}">
            </div>
        </x-filter-bar>

        <x-totals-bar :items="[
            'Requests' => number_format($totals['count']),
            'Face value' => $money($totals['volume']),
            'Charged' => $money($totals['charged']),
        ]" />

        {{-- Bulk action bar, shown only once something is selected. --}}
        <div x-show="selected.length" x-cloak
             class="flex flex-wrap items-center gap-3 border-b px-5 py-3"
             style="border-color: var(--line); background-color: var(--panel-muted);">
            <p class="text-sm font-medium">
                <span x-text="selected.length"></span> selected
            </p>
            <select name="action" class="select max-w-44" x-model="action">
                <option value="approve">Approve &amp; dispatch</option>
                <option value="cancel">Cancel &amp; refund</option>
            </select>
            <input name="reason" class="input max-w-64" placeholder="Reason (optional)" maxlength="200">
            <button type="submit" class="btn btn-primary btn-sm"
                    @click="return confirm(`Apply ${action} to ${selected.length} request(s)?`)">
                Apply
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="selected = []">Clear</button>
        </div>

        <div class="table-wrap">
            @if ($requests->isEmpty())
                <x-empty icon="check" title="Nothing waiting"
                         message="Every request has been handled." />
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-10">
                                <input type="checkbox" aria-label="Select all"
                                       @change="selected = $event.target.checked
                                           ? @js($requests->pluck('id')->map(fn ($i) => (string) $i)->all())
                                           : []">
                            </th>
                            <th>Reference</th>
                            <th>Reseller</th>
                            <th>Service</th>
                            <th>Number</th>
                            <th class="num">Amount</th>
                            <th class="num">Charged</th>
                            <th>Status</th>
                            <th>Waiting</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $request)
                            <tr>
                                <td>
                                    <input type="checkbox" name="ids[]" value="{{ $request->id }}"
                                           x-model="selected"
                                           aria-label="Select request {{ $request->sid }}">
                                </td>
                                <td>
                                    <a href="{{ route('admin.requests.show', $request) }}"
                                       class="font-mono text-xs hover:underline">{{ Str::limit($request->sid, 14, '') }}</a>
                                </td>
                                <td class="text-sm">{{ $request->reseller?->displayName() ?? '—' }}</td>
                                <td class="text-sm">{{ $request->serviceLabel() }}</td>
                                <td class="font-mono text-sm">{{ $request->phone }}</td>
                                <td class="num">{{ number_format((float) $request->balance) }}</td>
                                <td class="num">{{ number_format((float) $request->cost, 2) }}</td>
                                <td><x-status-badge :status="$request->status" /></td>
                                <td class="text-xs text-subtle">
                                    {{ $request->submitted_date?->diffForHumans(short: true) ?? '—' }}
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($request->status === \App\Enums\TransactionStatus::Waiting)
                                        <button type="submit" formaction="{{ route('admin.requests.approve', $request) }}"
                                                class="btn btn-primary btn-sm" title="Approve and dispatch">
                                            <x-icon name="check" class="size-4" />
                                        </button>
                                    @endif
                                    <button type="submit" formaction="{{ route('admin.requests.resend', $request) }}"
                                            class="btn btn-secondary btn-sm" title="Resend to provider">
                                        <x-icon name="refresh" class="size-4" />
                                    </button>
                                    <button type="submit" formaction="{{ route('admin.requests.cancel', $request) }}"
                                            class="btn btn-danger btn-sm" title="Cancel and refund"
                                            @click="return confirm('Cancel this request and refund the reseller?')">
                                        <x-icon name="x" class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($requests->hasPages())
            <div class="card-header border-t border-b-0">{{ $requests->links() }}</div>
        @endif
    </div>
</form>

@endsection
