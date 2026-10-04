@props(['name', 'label', 'help' => null, 'checked' => false])

{{-- A switch with its explanation. The hidden 0 field means an unchecked box
     still posts a value, so turning a setting off actually persists. --}}

@php
    $id = 'set-' . $name;
    $describedBy = $help ? $id . '-help' : null;
@endphp

<div class="flex items-start gap-3">
    <input type="hidden" name="{{ $name }}" value="0">

    <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="checkbox"
            value="1"
            class="peer sr-only"
            @checked(old($name, $checked))
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >
        <span class="h-5 w-9 rounded-full transition peer-checked:bg-brand-600"
              style="background-color: var(--line-strong);"></span>
        <span class="absolute left-0.5 size-4 rounded-full bg-white transition peer-checked:translate-x-4"></span>
    </label>

    <div class="min-w-0">
        <label for="{{ $id }}" class="block cursor-pointer text-sm font-medium">{{ $label }}</label>
        @if ($help)
            <p id="{{ $describedBy }}" class="mt-0.5 max-w-2xl text-sm text-subtle">{{ $help }}</p>
        @endif
    </div>
</div>
