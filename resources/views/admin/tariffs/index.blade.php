@extends('layouts.panel')

@section('title', 'Tariffs & Rates')
@section('heading', 'Tariffs & Rates')
@section('subheading', 'Price lists assigned to resellers')

@section('content')

<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.tariffs.index') }}" class="btn btn-primary btn-sm">Tariffs</a>
    <a href="{{ route('admin.tariffs.prices') }}" class="btn btn-secondary btn-sm">Base price matrix</a>
</div>

<div class="card">
    <div class="card-body">
        <p class="flex items-start gap-2 text-sm text-muted">
            <x-icon name="info" class="mt-0.5 size-4 shrink-0" style="color: var(--color-sky-600);" />
            <span>
                A tariff is a price list. Each reseller points at one, and its rows decide what that
                reseller pays per service and operator. Where a tariff has no row, the base price matrix
                applies instead.
            </span>
        </p>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><h2 class="card-title">All Tariffs</h2></div>
    <div class="table-wrap">
        @if ($tariffs->isEmpty())
            <x-empty icon="tag" title="No tariffs yet" />
        @else
            <table class="table">
                <thead><tr><th>Name</th><th>Owner</th><th>Created</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($tariffs as $tariff)
                        <tr>
                            <td>
                                <a href="{{ route('admin.tariffs.show', $tariff) }}" class="font-medium hover:underline">
                                    {{ $tariff->name }}
                                </a>
                            </td>
                            <td class="text-sm text-subtle">#{{ $tariff->userid }}</td>
                            <td class="text-xs text-subtle">{{ $tariff->date }}</td>
                            <td class="text-right">
                                <a href="{{ route('admin.tariffs.show', $tariff) }}" class="btn btn-secondary btn-sm">
                                    <x-icon name="edit" class="size-4" /> Rates
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if ($tariffs->hasPages())
        <div class="card-header border-t border-b-0">{{ $tariffs->links() }}</div>
    @endif
</div>

@endsection
