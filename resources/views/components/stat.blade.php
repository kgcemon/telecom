@props(['label', 'value', 'meta' => null, 'icon' => null, 'tone' => 'brand'])

{{-- A single figure with optional context line. Used across the dashboards. --}}

@php
    $tones = [
        'brand'   => 'var(--color-brand-500)',
        'emerald' => 'var(--color-emerald-500)',
        'amber'   => 'var(--color-amber-500)',
        'rose'    => 'var(--color-rose-500)',
        'sky'     => 'var(--color-sky-500)',
        'violet'  => 'var(--color-violet-500)',
    ];
    $accent = $tones[$tone] ?? $tones['brand'];
@endphp

<div {{ $attributes->merge(['class' => 'card stat']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="stat-label">{{ $label }}</p>
            <p class="stat-value">{{ $value }}</p>
            @if ($meta)
                <p class="stat-meta">{{ $meta }}</p>
            @endif
        </div>
        @if ($icon)
            <span class="grid size-9 shrink-0 place-items-center rounded-lg"
                  style="background-color: color-mix(in oklch, {{ $accent }} 14%, transparent); color: {{ $accent }};">
                <x-icon :name="$icon" class="size-5" />
            </span>
        @endif
    </div>
</div>
