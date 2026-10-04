@props(['status'])

{{-- Renders a TransactionStatus with its own colour, so status is readable
     at a glance in long transaction tables. --}}

@php
    $status = $status instanceof \App\Enums\TransactionStatus
        ? $status
        : \App\Enums\TransactionStatus::tryFrom((int) $status);

    $color = $status?->color() ?? 'slate';
    $accent = "var(--color-{$color}-500)";
    $text = "var(--color-{$color}-700)";
@endphp

<span class="badge"
      style="background-color: color-mix(in oklch, {{ $accent }} 15%, transparent); color: {{ $text }}; border-color: color-mix(in oklch, {{ $accent }} 30%, transparent);">
    {{ $status?->label() ?? 'Unknown' }}
</span>
