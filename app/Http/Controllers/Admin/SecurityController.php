<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\DeviceSession;
use App\Models\LoginAttempt;
use App\Models\OnlineUser;
use App\Models\Reseller;
use App\Services\Security\AuditLogger;
use App\Services\Security\LegacyHasher;
use App\Services\Security\PinService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Security visibility and the signed-in operator's own credentials.
 */
class SecurityController
{
    public function __construct(
        private readonly LegacyHasher $hasher,
        private readonly PinService $pins,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function devices(Request $request): View
    {
        return view('admin.security.devices', [
            'devices' => DeviceSession::query()
                ->when($request->filled('reseller'), fn ($q) => $q->where('userid', (string) $request->integer('reseller')))
                ->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25))
                ->withQueryString(),
            'filters' => $request->only(['reseller']),
        ]);
    }

    public function revokeDevice(Request $request, DeviceSession $device): RedirectResponse
    {
        $device->forceFill(['status' => 0])->save();

        $this->audit->record(
            $request->attributes->get('current_reseller'),
            'security.device_revoked',
            sprintf('Revoked device session %d for account %s', $device->id, $device->userid),
        );

        return back()->with('status', 'Device access revoked.');
    }

    public function logs(Request $request): View
    {
        return view('admin.security.logs', [
            'attempts' => LoginAttempt::query()
                ->when($request->filled('login'), fn ($q) => $q->where('login', 'like', '%'.$request->string('login')->toString().'%'))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
                ->orderByDesc('login_attempts_id')
                ->paginate(50)
                ->withQueryString(),
            'activity' => ActivityLog::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'filters' => $request->only(['login', 'type']),
        ]);
    }

    public function online(): View
    {
        // Anything without a heartbeat in the last five minutes is not online.
        $cutoff = now()->subMinutes(5)->getTimestamp();

        return view('admin.security.online', [
            'users' => OnlineUser::query()
                ->where('time', '>=', $cutoff)
                ->orderByDesc('time')
                ->limit(200)
                ->get(),
        ]);
    }

    public function edit(Request $request): View
    {
        return view('admin.security.edit', [
            'user'     => $request->attributes->get('current_reseller'),
            'security' => $this->settings->security(),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('current_reseller');

        $rules = $this->settings->security()->requiresStrongPassword()
            ? ['required', 'confirmed', Password::min(8)->letters()->numbers()->symbols()]
            : ['required', 'confirmed', 'string', 'min:8', 'max:100'];

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => $rules,
        ]);

        // Re-verify the current password: a hijacked session must not be able
        // to change the credential and lock the real owner out.
        if (! $this->hasher->check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Your current password is incorrect.']);
        }

        DB::table('reseller')->where('id', $user->id)->update([
            'password'      => $this->hasher->make($validated['password']),
            'chnage_pass_d' => now()->format('Y-m-d'),
            'chnage_pass_t' => now()->format('H:i:s'),
        ]);

        $this->audit->record($user, 'security.password_changed', 'Changed own password');

        return back()->with('status', 'Password changed.');
    }

    public function updatePin(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('current_reseller');
        $minimum = $this->settings->security()->minimumPinLength();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'pin'              => ['required', 'string', 'confirmed', 'min:'.$minimum, 'max:12'],
        ]);

        if (! $this->hasher->check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Your current password is incorrect.']);
        }

        if ($error = $this->pins->validateStrength($validated['pin'], $minimum)) {
            return back()->withErrors(['pin' => $error]);
        }

        $this->pins->store($user, $validated['pin']);

        $this->audit->record($user, 'security.pin_changed', 'Changed own transaction PIN');

        return back()->with('status', 'Transaction PIN changed.');
    }

    public function profile(Request $request): View
    {
        return view('admin.profile.edit', [
            'user' => $request->attributes->get('current_reseller'),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('current_reseller');

        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email'  => ['nullable', 'email', 'max:120'],
        ]);

        Reseller::query()->where('id', $user->id)->update($validated);

        $this->audit->record($user, 'profile.updated', 'Updated own profile');

        return back()->with('status', 'Profile updated.');
    }
}
