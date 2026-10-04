<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'Dashboard') &middot; {{ $brandName }}</title>

    {{--
        Applied before first paint so the panel never flashes the wrong theme.
        Wrapped in try/catch because localStorage throws in private windows.
    --}}
    <script nonce="{{ $csp_nonce ?? '' }}">
        (function () {
            try {
                var t = localStorage.getItem('telecom.theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" x-data="{ sidebarOpen: false }">

<div class="flex min-h-screen">

    {{-- Sidebar ------------------------------------------------------------ --}}

    <aside
        class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 overflow-y-auto border-r transition-transform lg:static lg:translate-x-0"
        style="background-color: var(--panel); border-color: var(--line);"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        x-cloak
    >
        <div class="flex h-16 items-center gap-2.5 border-b px-4" style="border-color: var(--line);">
            <span class="grid size-9 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">
                {{ $brandInitials }}
            </span>
            <span class="truncate text-sm font-semibold">{{ $brandName }}</span>
        </div>

        <nav class="p-3 pb-8" aria-label="Main navigation">
            @foreach ($navigation as $section => $items)
                @if ($items->isNotEmpty())
                    <p class="nav-section">{{ $section }}</p>
                    @foreach ($items as $item)
                        <a href="{{ $item['url'] }}"
                           class="nav-link {{ $item['active'] ? 'nav-link-active' : '' }}"
                           @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" class="size-4 shrink-0" />
                            <span class="truncate">{{ $item['label'] }}</span>
                            @if (! empty($item['badge']))
                                <span class="badge ml-auto"
                                      style="background-color: color-mix(in oklch, var(--color-rose-500) 16%, transparent); color: var(--color-rose-600);">
                                    {{ $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>
    </aside>

    {{-- Backdrop for the mobile drawer --}}
    <div x-show="sidebarOpen" x-cloak
         class="fixed inset-0 z-30 bg-black/40 lg:hidden"
         @click="sidebarOpen = false" aria-hidden="true"></div>

    {{-- Main --------------------------------------------------------------- --}}

    <div class="flex min-w-0 flex-1 flex-col">

        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b px-4 sm:px-6"
                style="background-color: color-mix(in oklch, var(--surface) 88%, transparent); backdrop-filter: blur(8px); border-color: var(--line);">

            <button type="button" class="btn btn-ghost btn-sm lg:hidden" @click="sidebarOpen = true"
                    aria-label="Open navigation">
                <x-icon name="menu" class="size-5" />
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold">@yield('heading', View::getSection('title', 'Dashboard'))</h1>
                @hasSection('subheading')
                    <p class="truncate text-xs text-subtle">@yield('subheading')</p>
                @endif
            </div>

            @if ($walletSummary !== null)
                <div class="hidden items-center gap-4 rounded-lg border px-3 py-1.5 sm:flex"
                     style="border-color: var(--line); background-color: var(--panel);">
                    @foreach ($walletSummary as $label => $value)
                        <div class="text-right">
                            <p class="text-[0.6875rem] leading-tight text-subtle">{{ $label }}</p>
                            <p class="text-sm font-semibold tnum leading-tight">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Theme switch --}}
            <button type="button" class="btn btn-ghost btn-sm"
                    @click="$store.theme.toggle()"
                    :aria-label="$store.theme.isDark() ? 'Switch to light theme' : 'Switch to dark theme'">
                <x-icon name="sun" class="size-5" x-show="$store.theme.isDark()" x-cloak />
                <x-icon name="moon" class="size-5" x-show="! $store.theme.isDark()" x-cloak />
            </button>

            {{-- Account menu --}}
            <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                <button type="button" class="btn btn-secondary btn-sm" @click="open = ! open"
                        :aria-expanded="open" aria-haspopup="menu">
                    <span class="grid size-6 place-items-center rounded-full bg-brand-600 text-[0.6875rem] font-semibold text-white">
                        {{ $userInitials }}
                    </span>
                    <span class="hidden max-w-32 truncate sm:inline">{{ $currentUser?->displayName() }}</span>
                    <x-icon name="chevron-down" class="size-4" />
                </button>

                <div x-show="open" x-cloak @click.outside="open = false"
                     class="card absolute right-0 mt-2 w-56 overflow-hidden p-1" role="menu">
                    <div class="border-b px-3 py-2" style="border-color: var(--line);">
                        <p class="truncate text-sm font-medium">{{ $currentUser?->displayName() }}</p>
                        <p class="truncate text-xs text-subtle">{{ $roleLabel }}</p>
                    </div>

                    <a href="{{ $profileUrl }}" class="nav-link" role="menuitem">
                        <x-icon name="user" class="size-4" /> Profile
                    </a>
                    <a href="{{ $securityUrl }}" class="nav-link" role="menuitem">
                        <x-icon name="shield" class="size-4" /> Password &amp; PIN
                    </a>

                    <form method="POST" action="{{ $logoutUrl }}" class="border-t pt-1" style="border-color: var(--line);">
                        @csrf
                        <button type="submit" class="nav-link w-full text-left" role="menuitem"
                                style="color: var(--color-rose-600);">
                            <x-icon name="logout" class="size-4" /> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <x-alerts />
            @yield('content')
        </main>

        <footer class="border-t px-6 py-4 text-xs text-subtle" style="border-color: var(--line);">
            {{ $brandName }} &middot; {{ now()->format('d M Y') }}
        </footer>
    </div>
</div>

{{-- Toasts --}}
<div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-2" aria-live="polite">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div class="card pointer-events-auto flex items-start gap-3 p-3" x-transition>
            <p class="flex-1 text-sm" x-text="toast.message"></p>
            <button type="button" class="btn btn-ghost btn-sm" @click="$store.toasts.dismiss(toast.id)"
                    aria-label="Dismiss">
                <x-icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>

<style nonce="{{ $csp_nonce ?? '' }}">[x-cloak] { display: none !important; }</style>

@stack('scripts')
</body>
</html>
