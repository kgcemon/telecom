@extends('layouts.panel')

@section('title', 'Base Prices')
@section('heading', 'Base Price Matrix')
@section('subheading', 'The platform cost and commission per service and operator')

@section('content')

<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.tariffs.index') }}" class="btn btn-secondary btn-sm">Tariffs</a>
    <a href="{{ route('admin.tariffs.prices') }}" class="btn btn-primary btn-sm">Base price matrix</a>
</div>

<form method="POST" action="{{ route('admin.tariffs.prices.update') }}" class="card">
    @csrf @method('PUT')

    <div class="card-header">
        <form method="GET" action="{{ route('admin.tariffs.prices') }}" class="flex items-end gap-3">
            <div>
                <label for="service" class="label">Service</label>
                <select id="service" name="service" class="select" onchange="this.form.submit()">
                    <option value="">All services</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->serviceid }}" @selected((string) $selected === (string) $service->serviceid)>
                            {{ $service->title }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>
        <button class="btn btn-primary btn-sm">Save all changes</button>
    </div>

    <div class="card-body">
        <p class="mb-4 flex items-start gap-2 text-sm text-muted">
            <x-icon name="info" class="mt-0.5 size-4 shrink-0" style="color: var(--color-sky-600);" />
            <span>
                <strong>Rate</strong> multiplies the amount (normally 1).
                <strong>Commission</strong> is the percentage discounted off.
                <strong>Charge</strong> is the percentage added on.
                A reseller pays <code>amount × rate + charge% − commission%</code>.
            </span>
        </p>
    </div>

    <div class="table-wrap">
        @if ($prices->isEmpty())
            <x-empty icon="tag" title="No price rows"
                     message="Without a price row the engine refuses the request rather than selling it for nothing." />
        @else
            <table class="table">
                <thead>
                    <tr><th>Service</th><th>Prefix</th><th>Operator</th><th>Type</th>
                        <th class="num">Rate</th><th class="num">Commission %</th><th class="num">Charge %</th></tr>
                </thead>
                <tbody>
                    @foreach ($prices as $index => $price)
                        <tr>
                            <input type="hidden" name="prices[{{ $index }}][id]" value="{{ $price->id }}">
                            <td class="text-sm">{{ $price->service }}</td>
                            <td class="font-mono text-sm">{{ $price->prefix }}</td>
                            <td class="text-sm">{{ $price->pcode }}</td>
                            <td class="text-sm text-subtle">{{ $price->type === '2' ? 'Postpaid' : 'Prepaid' }}</td>
                            <td><input class="input w-24" type="number" step="0.0001" min="0" max="10"
                                       name="prices[{{ $index }}][rate]" value="{{ (float) $price->rate }}"></td>
                            <td><input class="input w-24" type="number" step="0.0001" min="0" max="100"
                                       name="prices[{{ $index }}][comm]" value="{{ (float) $price->comm }}"></td>
                            <td><input class="input w-24" type="number" step="0.0001" min="0" max="100"
                                       name="prices[{{ $index }}][charge]" value="{{ (float) $price->charge }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</form>

@endsection
