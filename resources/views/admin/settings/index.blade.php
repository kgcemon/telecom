@extends('layouts.panel')

@section('title', 'Settings')
@section('heading', 'Settings')
@section('subheading', 'Every option below says what it does and what changing it affects')

@section('content')

<div x-data="{ tab: 'company' }">

    {{-- Tabs ------------------------------------------------------------- --}}

    <div class="mb-4 flex flex-wrap gap-1 border-b" style="border-color: var(--line);">
        @foreach ([
            'company'  => ['Company', 'settings'],
            'security' => ['Security & Sign-in', 'shield'],
            'limits'   => ['Transaction Limits', 'alert'],
            'wallets'  => ['Wallets & Commission', 'wallet'],
            'deposits' => ['Deposit Channels', 'card'],
        ] as $key => [$label, $icon])
            <button type="button" @click="tab = '{{ $key }}'"
                    class="flex items-center gap-2 rounded-t-lg px-3 py-2 text-sm font-medium transition"
                    :class="tab === '{{ $key }}' ? 'border-b-2' : 'text-muted hover:text-current'"
                    :style="tab === '{{ $key }}'
                        ? 'border-color: var(--color-brand-600); color: var(--color-brand-700);'
                        : ''">
                <x-icon :name="$icon" class="size-4" />
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Company ---------------------------------------------------------- --}}

    <div x-show="tab === 'company'" x-cloak>
        <form method="POST" action="{{ route('admin.settings.company') }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">Company Details</h2>
                    <p class="text-xs text-subtle">Shown on the sign-in page, in the panel header and on receipts.</p>
                </div>
            </div>

            <div class="card-body grid gap-5 sm:grid-cols-2">
                <x-setting-field name="company_name" label="Company name"
                    help="Appears in the panel header, the browser tab and the sign-in screen."
                    :value="$company->company_name" required />

                <x-setting-field name="company_title" label="Tagline"
                    help="Optional short line under the company name."
                    :value="$company->company_title" />

                <x-setting-field name="website" label="Website" type="url"
                    help="Public site address, linked from the footer."
                    :value="$company->website" />

                <x-setting-field name="helpdesk_mobile" label="Support phone"
                    help="Offered to resellers who cannot sign in, so keep it reachable."
                    :value="$company->helpdesk_mobile" />

                <x-setting-field name="helpdesk_email" label="Support email" type="email"
                    help="Where support replies are sent from."
                    :value="$company->helpdesk_email" />

                <x-setting-field name="contact_no1" label="Office phone"
                    :value="$company->contact_no1" />

                <x-setting-field name="address" label="Address" class="sm:col-span-2"
                    :value="$company->address" />

                <x-setting-field name="whatsapp" label="WhatsApp link"
                    help="Full link, for example https://wa.me/8801700000000"
                    :value="$company->whatsapp" />

                <x-setting-field name="telegram" label="Telegram link"
                    :value="$company->telegram" />

                <x-setting-field name="footer" label="Footer text" class="sm:col-span-2"
                    help="Small print at the bottom of every panel page."
                    :value="$company->footer" />
            </div>

            <div class="card-header justify-end border-t border-b-0">
                <button type="submit" class="btn btn-primary">Save company details</button>
            </div>
        </form>
    </div>

    {{-- Security --------------------------------------------------------- --}}

    <div x-show="tab === 'security'" x-cloak>
        <form method="POST" action="{{ route('admin.settings.security') }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">Security &amp; Sign-in</h2>
                    <p class="text-xs text-subtle">
                        Applies to every account. Rate limiting and account lockout are always on and
                        are configured in code, not here.
                    </p>
                </div>
            </div>

            <div class="card-body space-y-5">

                <x-setting-toggle name="admin_capcha" label="Security check on admin sign-in"
                    help="Adds a short arithmetic question to the admin login form. Worth leaving on — the admin panel can move money."
                    :checked="(int) $security->admin_capcha === 1" />

                <x-setting-toggle name="reseller_capcha" label="Security check on reseller sign-in"
                    help="Same question on the reseller login. Resellers sign in many times a day, so this is often left off."
                    :checked="(int) $security->reseller_capcha === 1" />

                <x-setting-toggle name="pass_stong" label="Require strong passwords"
                    help="New passwords must mix letters, numbers and a symbol, and be at least 8 characters. Existing passwords are not affected until next change."
                    :checked="(int) $security->pass_stong === 1" />

                <x-setting-toggle name="OTP" label="Require one-time code on sign-in"
                    help="Sends a code to the account mobile or email in addition to the password. Strongest protection available here, but needs a working SMS or mail gateway."
                    :checked="(int) $security->OTP === 1" />

                <div class="grid gap-5 border-t pt-5 sm:grid-cols-3" style="border-color: var(--line);">
                    <x-setting-field name="min_pin" label="Minimum PIN length" type="number"
                        unit="digits" min="4" max="12"
                        help="Transaction PINs shorter than this are rejected. Repeated digits (1111) and simple runs (1234) are always rejected regardless."
                        :value="$security->min_pin" required />

                    <x-setting-field name="pass_exp" label="Password expires after" type="number"
                        unit="days" min="0" max="3650"
                        help="Resellers are asked to set a new password after this many days. Enter 0 to never expire."
                        :value="$security->pass_exp" required />

                    <x-setting-field name="pin_exp" label="PIN expires after" type="number"
                        unit="days" min="0" max="3650"
                        help="Same, for transaction PINs. Enter 0 to never expire."
                        :value="$security->pin_exp" required />
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <x-setting-field name="session_time_out" label="Sign out when idle for" type="number"
                        unit="minutes" min="5" max="1440"
                        help="An unattended panel is signed out automatically after this long with no activity."
                        :value="$security->session_time_out" required />
                </div>
            </div>

            <div class="card-header justify-end border-t border-b-0">
                <button type="submit" class="btn btn-primary">Save security policy</button>
            </div>
        </form>
    </div>

    {{-- Limits ----------------------------------------------------------- --}}

    <div x-show="tab === 'limits'" x-cloak>
        <form method="POST" action="{{ route('admin.settings.limits') }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">Transaction Limits</h2>
                    <p class="text-xs text-subtle">
                        These decide what is sent automatically and what waits for an operator.
                    </p>
                </div>
            </div>

            <div class="card-body space-y-5">

                <div class="rounded-lg p-3 text-sm"
                     style="background-color: color-mix(in oklch, var(--color-sky-500) 8%, transparent);">
                    <p class="flex items-start gap-2">
                        <x-icon name="info" class="mt-0.5 size-4 shrink-0" style="color: var(--color-sky-600);" />
                        <span class="text-muted">
                            A separate per-service ceiling also applies, set on each service under
                            <a href="{{ route('admin.services.index') }}" class="font-medium underline">Services</a>.
                            Whichever limit is tighter wins, and compiled hard caps apply on top of both —
                            currently {{ $symbol }} {{ number_format((float) config('telecom.limits.max_single_request')) }}
                            per request.
                        </span>
                    </p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-setting-field name="req_Interval" label="Block identical repeats for" type="number"
                        unit="minutes" min="0" max="120"
                        help="Stops the same reseller sending the same amount to the same number twice. Guards against a double-tap in the app becoming two top-ups. Enter 0 to allow immediate repeats."
                        :value="$security->req_Interval" required />

                    <x-setting-field name="reseller_dayli_limit" label="Daily limit per reseller" type="number"
                        :unit="$symbol" min="0"
                        help="Total face value one reseller may send in a day. A compromised account cannot drain more than this before anyone notices."
                        :value="$security->reseller_dayli_limit" required />

                    <x-setting-field name="bkash_auto" label="Auto-dispatch ceiling for manual routes" type="number"
                        :unit="$symbol" min="0"
                        help="Requests on a route marked manual still go out automatically up to this amount. Above it they wait for approval. (Stored in the legacy column named bkash_auto, but it applies to every service.)"
                        :value="$security->bkash_auto" required />

                    <x-setting-field name="bulk_flexi_limit" label="Maximum rows per bulk upload" type="number"
                        unit="rows" min="0" max="5000"
                        help="Largest bulk flexiload batch a reseller may submit at once."
                        :value="$security->bulk_flexi_limit" required />
                </div>

                <div class="border-t pt-5" style="border-color: var(--line);">
                    <x-setting-toggle name="reseller_OverPayment" label="Allow resellers to go overdrawn"
                        help="When on, a reseller may spend past a zero balance down to their credit limit. Leave off unless you extend credit deliberately — it is the difference between a bad debt and a declined request."
                        :checked="(int) $security->reseller_OverPayment === 1" />
                </div>
            </div>

            <div class="card-header justify-end border-t border-b-0">
                <button type="submit" class="btn btn-primary">Save limits</button>
            </div>
        </form>
    </div>

    {{-- Wallets ---------------------------------------------------------- --}}

    <div x-show="tab === 'wallets'" x-cloak>
        <form method="POST" action="{{ route('admin.settings.wallets') }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">Wallets &amp; Commission</h2>
                    <p class="text-xs text-subtle">
                        Which balance pays for which service, and how reseller margin is calculated.
                    </p>
                </div>
            </div>

            <div class="card-body space-y-5">

                <x-setting-toggle name="balance_system" label="Separate bank wallet"
                    help="When on, mobile-wallet and banking services (bKash, Nagad, Rocket, bill pay, bank transfer) are billed to a second balance, while airtime and internet come from the main balance. Useful when the two are funded from different sources. When off, everything uses the main balance."
                    :checked="$security->splitWalletsEnabled()" />

                <x-setting-toggle name="drive" label="Separate drive wallet for packages"
                    help="When on, fixed bundles (minute and internet packs) are billed to a third balance instead of the main one."
                    :checked="$security->driveWalletEnabled()" />

                <x-setting-toggle name="bal_transfer" label="Allow reseller-to-reseller transfers"
                    help="Lets a reseller move balance to another reseller under them, without going through you. Convenient, but it also means balance can move without an admin record of why."
                    :checked="$security->balanceTransferEnabled()" />

                <x-setting-toggle name="modem" label="On-premise modem software handles local dispatch"
                    help="Turn on if you run modem software that polls for requests itself. When off, this panel drives the modems directly."
                    :checked="$security->modemHandledExternally()" />

                <div class="border-t pt-5" style="border-color: var(--line);">
                    <label class="label" for="com_system">Commission mode</label>
                    <select id="com_system" name="com_system" class="select max-w-md">
                        <option value="0" @selected($security->commissionMode() === 0)>
                            Off — resellers pay the face value
                        </option>
                        <option value="1" @selected($security->commissionMode() === 1)>
                            Percentage — a share of each amount, from the price table
                        </option>
                        <option value="2" @selected($security->commissionMode() === 2)>
                            Per-tariff table — each tariff sets its own rates
                        </option>
                    </select>
                    <p class="field-hint max-w-md">
                        Decides where a reseller's discount comes from. Per-tariff is the most flexible and
                        is what lets different levels of the network buy at different prices; configure the
                        numbers under <a href="{{ route('admin.tariffs.index') }}" class="underline">Tariffs &amp; Rates</a>.
                    </p>
                </div>
            </div>

            <div class="card-header justify-end border-t border-b-0">
                <button type="submit" class="btn btn-primary">Save wallet configuration</button>
            </div>
        </form>
    </div>

    {{-- Deposits --------------------------------------------------------- --}}

    <div x-show="tab === 'deposits'" x-cloak>
        <form method="POST" action="{{ route('admin.settings.deposits') }}" class="card">
            @csrf @method('PUT')

            <div class="card-header">
                <div>
                    <h2 class="card-title">Deposit Channels</h2>
                    <p class="text-xs text-subtle">
                        The numbers resellers send money to when topping up. Shown to them on the
                        Add Balance screen.
                    </p>
                </div>
            </div>

            <div class="card-body space-y-4">
                @foreach ($gateways as $index => $gateway)
                    <div class="rounded-lg border p-4" style="border-color: var(--line);">
                        <input type="hidden" name="gateways[{{ $index }}][id]" value="{{ $gateway->id }}">

                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="font-medium">{{ $gateway->name }}</p>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="gateways[{{ $index }}][status]" value="1"
                                       @checked((int) $gateway->status === 1)
                                       class="size-4 rounded">
                                <span class="text-muted">Accepting deposits</span>
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="label" for="gw-number-{{ $index }}">Receiving number</label>
                                <input id="gw-number-{{ $index }}" class="input font-mono"
                                       name="gateways[{{ $index }}][number]"
                                       value="{{ $gateway->number }}" maxlength="11" inputmode="numeric">
                            </div>
                            <div>
                                <label class="label" for="gw-tol-{{ $index }}">Amount tolerance</label>
                                <input id="gw-tol-{{ $index }}" class="input" type="number" min="0" max="1000"
                                       name="gateways[{{ $index }}][tolerance]"
                                       value="{{ (int) $gateway->tolerance }}">
                                <p class="field-hint">
                                    How far a deposit may differ from the claimed amount and still match
                                    automatically. Keep small.
                                </p>
                            </div>
                            <div>
                                <label class="label" for="gw-note-{{ $index }}">Instructions</label>
                                <input id="gw-note-{{ $index }}" class="input"
                                       name="gateways[{{ $index }}][note]"
                                       value="{{ $gateway->note }}" maxlength="500"
                                       placeholder="e.g. Send money only, not payment">
                            </div>
                        </div>
                    </div>
                @endforeach

                @if ($gateways->isEmpty())
                    <x-empty icon="card" title="No deposit channels configured"
                             message="Add rows to the gateway table to offer manual top-up options." />
                @endif
            </div>

            <div class="card-header justify-end border-t border-b-0">
                <button type="submit" class="btn btn-primary">Save deposit channels</button>
            </div>
        </form>
    </div>
</div>

@endsection
