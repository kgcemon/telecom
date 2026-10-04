@extends('layouts.panel')

@section('title', 'Operators')
@section('heading', 'Operators')
@section('subheading', 'Networks and the number prefixes that identify them')

@section('content')

<div class="grid gap-4 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Configured Operators</h2></div>
        <div class="table-wrap">
            @if ($operators->isEmpty())
                <x-empty icon="phone" title="No operators configured" />
            @else
                <table class="table">
                    <thead><tr><th>Operator</th><th>Short code</th><th>Prefix</th><th>Status</th><th class="text-right">Save</th></tr></thead>
                    <tbody>
                        @foreach ($operators as $operator)
                            <tr>
                                <form method="POST" action="{{ route('admin.operators.update', $operator) }}" id="op-{{ $operator->id }}">
                                    @csrf @method('PUT')
                                </form>
                                <td><input class="input" name="title_op" form="op-{{ $operator->id }}" value="{{ $operator->title_op }}"></td>
                                <td><input class="input w-20 font-mono" name="ex" form="op-{{ $operator->id }}" value="{{ $operator->ex }}" maxlength="10"></td>
                                <td><input class="input w-24 font-mono" name="pcode" form="op-{{ $operator->id }}" value="{{ $operator->pcode }}" maxlength="11"></td>
                                <td>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="hidden" name="status" value="0" form="op-{{ $operator->id }}">
                                        <input type="checkbox" name="status" value="1" class="size-4 rounded"
                                               form="op-{{ $operator->id }}" @checked((int) $operator->status === 1)>
                                        Enabled
                                    </label>
                                </td>
                                <td class="text-right">
                                    <button class="btn btn-secondary btn-sm" form="op-{{ $operator->id }}">
                                        <x-icon name="check" class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.operators.store') }}" class="card">
        @csrf
        <div class="card-header"><h2 class="card-title">Add Operator</h2></div>
        <div class="card-body space-y-4">
            <x-setting-field name="title_op" label="Display name" required />
            <x-setting-field name="opname" label="Internal slug" required
                help="Lowercase identifier stored on each transaction, for example gp or robi." />
            <x-setting-field name="ex" label="Short code" required
                help="Two letters used in pricing and routing, for example GP." />
            <x-setting-field name="pcode" label="Number prefix" required
                help="The leading digits, for example 017. Adding it here is all the recharge engine needs." />
            <x-setting-toggle name="status" label="Enabled" :checked="true" />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary btn-sm">Add operator</button>
        </div>
    </form>
</div>

<div class="card mt-4">
    <div class="card-header">
        <div>
            <h2 class="card-title">Country Prefixes</h2>
            <p class="text-xs text-subtle">Per-country prefix table, used for number validation.</p>
        </div>
    </div>
    <div class="table-wrap">
        @if ($prefixes->isEmpty())
            <x-empty icon="globe" title="No country prefixes configured" />
        @else
            <table class="table">
                <thead><tr><th>Prefix</th><th>Operator</th><th>Code</th><th class="num">Expected length</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($prefixes as $prefix)
                        <tr>
                            <td class="font-mono">{{ $prefix->oprefix }}</td>
                            <td>{{ $prefix->op_name }}</td>
                            <td class="font-mono">{{ $prefix->code }}</td>
                            <td class="num">{{ $prefix->lenth ?: '—' }}</td>
                            <td>{{ (int) $prefix->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@endsection
