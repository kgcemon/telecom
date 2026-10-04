@props(['action', 'hasFilters' => false])

{{-- A GET filter form. Uses GET so a filtered view is a shareable URL and the
     browser back button behaves, which the legacy POST-based filters broke. --}}

<form method="GET" action="{{ $action }}" class="card-header flex-wrap gap-3">
    <div class="flex flex-wrap items-end gap-3">
        {{ $slot }}
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
            <x-icon name="filter" class="size-4" /> Apply
        </button>
        @if ($hasFilters)
            <a href="{{ $action }}" class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </div>
</form>
