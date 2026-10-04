@props(['items'])

{{-- Aggregate figures for the current filter, so a filtered list always shows
     what it sums to rather than only the visible page. --}}

<div class="flex flex-wrap gap-x-6 gap-y-2 border-b px-5 py-3 text-sm" style="border-color: var(--line);">
    @foreach ($items as $label => $value)
        <div>
            <span class="text-subtle">{{ $label }}:</span>
            <span class="ml-1 font-semibold tnum">{{ $value }}</span>
        </div>
    @endforeach
</div>
