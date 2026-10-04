import Alpine from 'alpinejs';

/*
 * ApexCharts is ~900 kB of the bundle but is only needed on the few pages that
 * actually draw a chart. It is imported on demand so the panel stays usable on
 * a slow mobile connection, which is how most resellers reach it. The promise
 * is cached so several charts on one page share a single download.
 */
let apexPromise = null;

function loadApex() {
    apexPromise ??= import('apexcharts').then((m) => m.default);

    return apexPromise;
}

/* ---------------------------------------------------------------------------
   Theme
   Applied before paint by an inline script in the layout head, so there is no
   flash of the wrong theme. This module only handles switching it afterwards.
   --------------------------------------------------------------------------- */

const THEME_KEY = 'telecom.theme';

function readStoredTheme() {
    try {
        return localStorage.getItem(THEME_KEY);
    } catch {
        // Private browsing, or site data blocked. Fall back to the OS setting.
        return null;
    }
}

function storeTheme(value) {
    try {
        localStorage.setItem(THEME_KEY, value);
    } catch {
        /* nothing we can do; the choice just will not persist */
    }
}

Alpine.store('theme', {
    current: readStoredTheme() ?? 'system',

    init() {
        this.apply();
    },

    set(value) {
        this.current = value;
        storeTheme(value);
        this.apply();
    },

    toggle() {
        this.set(this.isDark() ? 'light' : 'dark');
    },

    isDark() {
        return this.current === 'system'
            ? window.matchMedia('(prefers-color-scheme: dark)').matches
            : this.current === 'dark';
    },

    apply() {
        const root = document.documentElement;

        if (this.current === 'system') {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', this.current);
        }

        // Tell any mounted chart to re-theme itself.
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: this.isDark() } }));
    },
});

/* ---------------------------------------------------------------------------
   Charts
   A thin Alpine wrapper so a Blade view can mount a chart declaratively and
   have it follow the theme without any per-page JavaScript.
   --------------------------------------------------------------------------- */

function chartTheme(dark) {
    const styles = getComputedStyle(document.documentElement);
    const ink = styles.getPropertyValue('--ink-muted').trim();
    const line = styles.getPropertyValue('--line').trim();

    return {
        chart: {
            background: 'transparent',
            foreColor: ink,
            toolbar: { show: false },
            fontFamily: 'inherit',
            animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches },
        },
        theme: { mode: dark ? 'dark' : 'light' },
        grid: { borderColor: line, strokeDashArray: 4 },
        tooltip: { theme: dark ? 'dark' : 'light' },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'right', markers: { radius: 3 } },
    };
}

function deepMerge(base, extra) {
    const out = { ...base };

    for (const [key, value] of Object.entries(extra ?? {})) {
        out[key] =
            value && typeof value === 'object' && !Array.isArray(value)
                ? deepMerge(base[key] ?? {}, value)
                : value;
    }

    return out;
}

Alpine.data('chart', (options = {}) => ({
    instance: null,

    init() {
        const render = async () => {
            const ApexCharts = await loadApex();
            const dark = Alpine.store('theme').isDark();
            const config = deepMerge(chartTheme(dark), options);

            if (this.instance) {
                this.instance.updateOptions(config, false, false);
                return;
            }

            this.instance = new ApexCharts(this.$el, config);
            this.instance.render();
        };

        render();

        this.onThemeChange = () => render();
        window.addEventListener('theme-changed', this.onThemeChange);
    },

    destroy() {
        window.removeEventListener('theme-changed', this.onThemeChange);
        this.instance?.destroy();
    },
}));

/* ---------------------------------------------------------------------------
   Confirm
   Guards destructive submits. Replaces the legacy panel's inline
   `onclick="return confirm(...)"`, which a Content Security Policy forbids.
   --------------------------------------------------------------------------- */

Alpine.data('confirmAction', (message = 'Are you sure?') => ({
    open: false,

    request(event) {
        if (this.confirmed) {
            return;
        }

        event.preventDefault();
        this.open = true;
    },

    accept() {
        this.confirmed = true;
        this.open = false;
        this.$root.querySelector('form')?.submit();
    },

    message,
    confirmed: false,
}));

/* ---------------------------------------------------------------------------
   Toasts
   --------------------------------------------------------------------------- */

Alpine.store('toasts', {
    items: [],
    nextId: 1,

    push(message, type = 'info', timeout = 5000) {
        const id = this.nextId++;
        this.items.push({ id, message, type });

        if (timeout > 0) {
            setTimeout(() => this.dismiss(id), timeout);
        }
    },

    dismiss(id) {
        this.items = this.items.filter((item) => item.id !== id);
    },
});

/* ---------------------------------------------------------------------------
   Recharge form
   Lives in the bundle rather than in an inline <script> on the page: a
   per-page registration has to win a race with this module's Alpine.start(),
   and when it loses, every binding on the form silently evaluates against an
   empty scope. The page still supplies its own data through the x-data
   argument, so only the behaviour is shared.
   --------------------------------------------------------------------------- */

Alpine.data('rechargeForm', (config) => ({
    services: config.services ?? [],
    symbol: config.symbol ?? '',

    service: null,
    number: config.old?.number ?? '',
    amount: config.old?.amount ?? '',
    type: config.old?.type ?? '1',
    packageId: null,
    packages: [],

    operator: null,
    operatorError: null,
    quote: null,
    loadingQuote: false,
    submitting: false,

    init() {
        // Restore the chosen service after a validation error round-trip.
        if (config.old?.service) {
            const found = this.services.find((s) => s.id === Number(config.old.service));

            if (found) {
                this.service = found;
            }
        }

        if (this.number) {
            this.lookupOperator();
        }
    },

    get canSubmit() {
        if (!this.service || !this.number || !this.amount) {
            return false;
        }

        // Block a known-bad quote, but allow submission while one is still
        // loading so a slow connection cannot trap the user on the form.
        return !this.quote || (this.quote.resolved && this.quote.affordable);
    },

    selectService(svc) {
        this.service = svc;
        this.packageId = null;
        this.packages = [];
        this.quote = null;

        if (svc.has_packages) {
            this.loadPackages();
        }

        this.refreshQuote();
    },

    selectPackage(pack) {
        this.packageId = pack.id;
        this.amount = pack.price;
        this.refreshQuote();
    },

    clearPackage() {
        this.packageId = null;
        this.refreshQuote();
    },

    async lookupOperator() {
        this.operatorError = null;

        if (!this.number || this.number.length < 4) {
            this.operator = null;
            return;
        }

        try {
            const url = new URL(config.operatorUrl, window.location.origin);
            url.searchParams.set('number', this.number);

            const res = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!res.ok) {
                return;
            }

            const data = await res.json();
            this.operator = data.operator;

            if (data.operator && !data.enabled) {
                this.operatorError = 'This operator is switched off at the moment.';
            } else if (!data.operator && this.number.length >= 6) {
                this.operatorError = 'Unrecognised number — check the prefix.';
            }

            if (this.service?.has_packages) {
                this.loadPackages();
            }

            this.refreshQuote();
        } catch {
            // Keep the previous value; the server revalidates on submit anyway.
        }
    },

    async loadPackages() {
        if (!this.service?.has_packages) {
            this.packages = [];
            return;
        }

        try {
            const url = new URL(config.packagesUrl, window.location.origin);
            url.searchParams.set('service', this.service.id);

            if (this.operator) {
                url.searchParams.set('operator', this.operator);
            }

            const res = await fetch(url, { headers: { Accept: 'application/json' } });

            this.packages = res.ok ? ((await res.json()).packages ?? []) : [];
        } catch {
            this.packages = [];
        }
    },

    async refreshQuote() {
        if (!this.service || !this.number || !this.amount || Number(this.amount) <= 0) {
            this.quote = null;
            return;
        }

        this.loadingQuote = true;

        try {
            const res = await fetch(config.quoteUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    service: this.service.id,
                    number: this.number,
                    amount: this.amount,
                    type: this.type,
                    package: this.packageId,
                }),
            });

            this.quote = res.ok ? await res.json() : null;
        } catch {
            this.quote = null;
        } finally {
            this.loadingQuote = false;
        }
    },
}));

window.Alpine = Alpine;

Alpine.start();
