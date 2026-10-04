@props([
    'name',
    'label',
    'value' => null,
    'type' => 'text',
    'help' => null,
    'unit' => null,
    'required' => false,
    'min' => null,
    'max' => null,
])

{{-- A labelled input with its explanation attached, so no setting is a bare
     database column name. `help` is rendered as the accessible description. --}}

@php
    $id = 'set-' . $name;
    $describedBy = $help ? $id . '-help' : null;
@endphp

<div {{ $attributes->merge(['class' => '']) }}>
    <label for="{{ $id }}" class="label">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" style="color: var(--color-rose-500);">*</span>
        @endif
    </label>

    <div class="relative">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ old($name, $value) }}"
            class="input {{ $unit ? 'pr-16' : '' }}"
            @if ($required) required @endif
            @if ($min !== null) min="{{ $min }}" @endif
            @if ($max !== null) max="{{ $max }}" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @error($name) aria-invalid="true" @enderror
        >
        @if ($unit)
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-subtle">
                {{ $unit }}
            </span>
        @endif
    </div>

    @error($name)
        <p class="field-error">{{ $message }}</p>
    @else
        @if ($help)
            <p id="{{ $describedBy }}" class="field-hint">{{ $help }}</p>
        @endif
    @enderror
</div>
