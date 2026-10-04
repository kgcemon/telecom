@extends('layouts.panel')

@section('title', $tariff->name)
@section('heading', $tariff->name)
@section('subheading', 'Rates for every reseller on this tariff')

@section('content')

<form method="POST" action="{{ route('admin.tariffs.update', $tariff) }}" class="card">
    @csrf @method('PUT')

    <div class="card-header">
        <h2 class="card-title">Tariff</h2>
        <button class="btn btn-primary btn-sm">Save</button>
    </div>

    <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-setting-field name="name" label="Tariff name" :value="$tariff->name" required />
        <x-setting-field name="desc" label="Description" :value="$tariff->desc" />
    </div>

    <div class="table-wrap border-t" style="border-color: var(--line);">
        @if ($rates->isEmpty())
            <x-empty icon="tag" title="No rate rows on this tariff"
                     message="Resellers on it fall back to the base price matrix." />
        @else
            <table class="table">
                <thead>
                    <tr><th>Service</th><th>Operator</th><th class="num">Commission %</th>
                        <th class="num">Charge %</th><th>Active</th></tr>
                </thead>
                <tbody>
                    @foreach ($rates as $index => $rate)
                        <tr>
                            <input type="hidden" name="rates[{{ $index }}][id]" value="{{ $rate->id }}">
                            <td class="text-sm">{{ $rate->service }}</td>
                            <td class="text-sm">{{ $rate->pcode }}</td>
                            <td><input class="input w-24" type="number" step="0.0001" min="0" max="100"
                                       name="rates[{{ $index }}][comm]" value="{{ (float) $rate->comm }}"></td>
                            <td><input class="input w-24" type="number" step="0.0001" min="0" max="100"
                                       name="rates[{{ $index }}][charge]" value="{{ (float) $rate->charge }}"></td>
                            <td>
                                <input type="hidden" name="rates[{{ $index }}][status]" value="0">
                                <input type="checkbox" name="rates[{{ $index }}][status]" value="1"
                                       class="size-4 rounded" @checked((int) $rate->status === 1)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</form>

@endsection
