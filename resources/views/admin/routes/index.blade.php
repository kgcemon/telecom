@extends('layouts.panel')

@section('title', 'Routing')
@section('heading', 'Routing')
@section('subheading', 'Which provider fulfils which service and operator')

@section('content')

<div class="card mb-4">
    <div class="card-body">
        <p class="flex items-start gap-2 text-sm text-muted">
            <x-icon name="info" class="mt-0.5 size-4 shrink-0" style="color: var(--color-sky-600);" />
            <span>
                Routes are listed in the order the engine picks them: an operator-specific route beats
                the <code>all</code> catch-all, and within that the lowest priority number wins.
                Set a route to <code>manual</code> to hold its requests for review instead of sending them upstream.
            </span>
        </p>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Active Routes</h2></div>
        <div class="table-wrap">
            @if ($routes->isEmpty())
                <x-empty icon="route" title="No routes configured"
                         message="Without a route, every request waits for manual approval." />
            @else
                <table class="table">
                    <thead><tr><th>Title</th><th>Service</th><th>Operator</th><th>Provider</th>
                        <th class="num">Priority</th><th>Active</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($routes as $route)
                            <tr>
                                <form method="POST" action="{{ route('admin.routes.update', $route) }}" id="rt-{{ $route->id }}">
                                    @csrf @method('PUT')
                                </form>
                                <td><input class="input" name="title" form="rt-{{ $route->id }}" value="{{ $route->title }}"></td>
                                <td>
                                    <select class="select" name="service" form="rt-{{ $route->id }}">
                                        @foreach ($services as $service)
                                            <option value="{{ $service->serviceid }}" @selected((string) $route->service === (string) $service->serviceid)>
                                                {{ $service->title }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select class="select w-28" name="pcode" form="rt-{{ $route->id }}">
                                        <option value="all" @selected($route->pcode === 'all')>All</option>
                                        @foreach ($operators as $operator)
                                            <option value="{{ $operator->ex }}" @selected($route->pcode === $operator->ex)>{{ $operator->ex }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select class="select" name="route" form="rt-{{ $route->id }}">
                                        <option value="manual" @selected($route->isManual())>Manual review</option>
                                        @foreach ($providers as $provider)
                                            <option value="{{ $provider->id }}" @selected((string) $route->route === (string) $provider->id)>
                                                {{ $provider->api_title }}{{ (int) $provider->status === 1 ? '' : ' (disabled)' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input class="input w-20" type="number" name="priority" form="rt-{{ $route->id }}" value="{{ $route->priority }}" min="0" max="99"></td>
                                <td>
                                    <input type="hidden" name="active" value="0" form="rt-{{ $route->id }}">
                                    <input type="checkbox" name="active" value="1" class="size-4 rounded"
                                           form="rt-{{ $route->id }}" @checked((int) $route->active === 1)>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <button class="btn btn-secondary btn-sm" form="rt-{{ $route->id }}"><x-icon name="check" class="size-4" /></button>
                                    <form method="POST" action="{{ route('admin.routes.destroy', $route) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger btn-sm" onclick="return confirm('Delete this route?')">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.routes.store') }}" class="card">
        @csrf
        <div class="card-header"><h2 class="card-title">Add Route</h2></div>
        <div class="card-body space-y-4">
            <x-setting-field name="title" label="Title" required />

            <div>
                <label for="set-service" class="label">Service <span style="color: var(--color-rose-500);">*</span></label>
                <select id="set-service" name="service" class="select" required>
                    @foreach ($services as $service)
                        <option value="{{ $service->serviceid }}">{{ $service->title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="set-pcode" class="label">Operator <span style="color: var(--color-rose-500);">*</span></label>
                <select id="set-pcode" name="pcode" class="select" required>
                    <option value="all">All operators</option>
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->ex }}">{{ $operator->title_op }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="set-route" class="label">Provider <span style="color: var(--color-rose-500);">*</span></label>
                <select id="set-route" name="route" class="select" required>
                    <option value="manual">Manual review</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->api_title }}</option>
                    @endforeach
                </select>
            </div>

            <x-setting-field name="priority" label="Priority" type="number" min="0" max="99" value="0" required
                help="Lower wins when several routes match." />

            <x-setting-toggle name="active" label="Active" :checked="true" />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary btn-sm">Add route</button>
        </div>
    </form>
</div>

@endsection
