<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in &middot; {{ $brandName }}</title>

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center p-4">

<div class="w-full max-w-sm">

    <div class="mb-6 text-center">
        <span class="mx-auto grid size-12 place-items-center rounded-xl bg-brand-600 text-base font-bold text-white">
            {{ $brandInitials }}
        </span>
        <h1 class="mt-3 text-lg font-semibold">{{ $brandName }}</h1>
        <p class="text-sm text-subtle">
            {{ $panel === 'admin' ? 'Administrator sign-in' : 'Reseller sign-in' }}
        </p>
    </div>

    <div class="card p-5">

        @if ($errors->has('username'))
            <div class="mb-4 rounded-lg p-3"
                 style="background-color: color-mix(in oklch, var(--color-rose-500) 10%, transparent);"
                 role="alert">
                <p class="text-sm" style="color: var(--color-rose-700);">{{ $errors->first('username') }}</p>
            </div>
        @endif

        @if (session('status'))
            <div class="mb-4 rounded-lg p-3"
                 style="background-color: color-mix(in oklch, var(--color-emerald-500) 10%, transparent);"
                 role="status">
                <p class="text-sm" style="color: var(--color-emerald-700);">{{ session('status') }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route($panel === 'admin' ? 'admin.login.store' : 'reseller.login.store') }}"
              x-data="{ busy: false, show: false }" @submit="busy = true">
            @csrf

            <div class="mb-4">
                <label for="username" class="label">Username</label>
                <input id="username" name="username" type="text" class="input"
                       value="{{ old('username') }}" required autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       @if ($errors->has('username')) aria-invalid="true" @endif>
            </div>

            <div class="mb-4">
                <label for="password" class="label">Password</label>
                <div class="relative">
                    <input id="password" name="password" class="input pr-10"
                           :type="show ? 'text' : 'password'" required
                           autocomplete="current-password">
                    <button type="button"
                            class="absolute inset-y-0 right-0 grid w-10 place-items-center text-subtle"
                            @click="show = ! show"
                            :aria-label="show ? 'Hide password' : 'Show password'">
                        <x-icon name="eye" class="size-4" />
                    </button>
                </div>
            </div>

            @if ($captchaRequired)
                <div class="mb-4">
                    <label for="captcha" class="label">
                        Security check &mdash; what is {{ $captchaQuestion }}?
                    </label>
                    <input id="captcha" name="captcha" type="text" inputmode="numeric"
                           class="input" required autocomplete="off">
                    <input type="hidden" name="captcha_token" value="{{ $captchaToken }}">
                </div>
            @endif

            <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="busy">
                <span x-show="! busy">Sign in</span>
                <span x-show="busy" x-cloak>Signing in&hellip;</span>
            </button>
        </form>

        @if ($panel !== 'admin')
            <p class="mt-4 text-center text-sm text-subtle">
                Trouble signing in? Contact your upline or
                <a href="{{ $supportContact ? 'tel:'.$supportContact : '#' }}"
                   class="font-medium" style="color: var(--color-brand-600);">support</a>.
            </p>
        @endif
    </div>

    <div class="mt-4 flex items-center justify-center gap-3 text-xs text-subtle">
        <button type="button" @click="$store.theme.toggle()" class="btn btn-ghost btn-sm">
            <x-icon name="sun" class="size-4" x-show="$store.theme.isDark()" x-cloak />
            <x-icon name="moon" class="size-4" x-show="! $store.theme.isDark()" x-cloak />
            <span>Theme</span>
        </button>
        <span>&middot;</span>
        <span>{{ now()->format('d M Y') }}</span>
    </div>

    @if ($loginNotice)
        <div class="card mt-4 p-3">
            <p class="text-xs text-muted">{!! $loginNotice !!}</p>
        </div>
    @endif
</div>

<style nonce="{{ $csp_nonce ?? '' }}">[x-cloak] { display: none !important; }</style>
</body>
</html>
