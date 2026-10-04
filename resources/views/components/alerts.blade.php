{{-- Flash messages and validation summary, rendered once in the layout. --}}

@if (session('status'))
    <div class="card mb-4 flex items-start gap-3 p-3"
         style="border-color: color-mix(in oklch, var(--color-emerald-500) 40%, var(--line)); background-color: color-mix(in oklch, var(--color-emerald-500) 10%, var(--panel));"
         role="status">
        <x-icon name="check" class="mt-0.5 size-5" style="color: var(--color-emerald-600);" />
        <p class="text-sm">{{ session('status') }}</p>
    </div>
@endif

@if (session('error'))
    <div class="card mb-4 flex items-start gap-3 p-3"
         style="border-color: color-mix(in oklch, var(--color-rose-500) 40%, var(--line)); background-color: color-mix(in oklch, var(--color-rose-500) 10%, var(--panel));"
         role="alert">
        <x-icon name="alert" class="mt-0.5 size-5" style="color: var(--color-rose-600);" />
        <p class="text-sm">{{ session('error') }}</p>
    </div>
@endif

@if (session('warning'))
    <div class="card mb-4 flex items-start gap-3 p-3"
         style="border-color: color-mix(in oklch, var(--color-amber-500) 40%, var(--line)); background-color: color-mix(in oklch, var(--color-amber-500) 10%, var(--panel));"
         role="alert">
        <x-icon name="alert" class="mt-0.5 size-5" style="color: var(--color-amber-600);" />
        <p class="text-sm">{{ session('warning') }}</p>
    </div>
@endif

@if ($errors->any() && ! $errors->has('username'))
    <div class="card mb-4 p-3"
         style="border-color: color-mix(in oklch, var(--color-rose-500) 40%, var(--line)); background-color: color-mix(in oklch, var(--color-rose-500) 8%, var(--panel));"
         role="alert">
        <div class="flex items-start gap-3">
            <x-icon name="alert" class="mt-0.5 size-5 shrink-0" style="color: var(--color-rose-600);" />
            <div class="min-w-0">
                <p class="text-sm font-medium">Please correct the following:</p>
                <ul class="mt-1 list-inside list-disc text-sm text-muted">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
