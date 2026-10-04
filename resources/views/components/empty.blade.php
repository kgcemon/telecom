@props(['icon' => 'box', 'title' => 'Nothing here yet', 'message' => null])

<div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <span class="grid size-12 place-items-center rounded-full"
          style="background-color: var(--panel-muted); color: var(--ink-subtle);">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <p class="mt-3 text-sm font-medium">{{ $title }}</p>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-subtle">{{ $message }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
