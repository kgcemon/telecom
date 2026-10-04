<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

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

/** A reseller's own profile and credentials. */
class ProfileController
{
    public function __construct(
        private readonly LegacyHasher $hasher,
        private readonly PinService $pins,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): View
    {
        return view('reseller.profile.edit', [
            'user'   => $this->user($request),
            'symbol' => $this->settings->currencySymbol(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email'  => ['nullable', 'email', 'max:120'],
        ]);

        Reseller::query()->where('id', $user->id)->update($validated);

        $this->audit->record($user, 'profile.updated', 'Updated own profile');

        return back()->with('status', 'Profile updated.');
    }

    public function security(Request $request): View
    {
        return view('reseller.security.edit', [
            'user'     => $this->user($request),
            'security' => $this->settings->security(),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $rules = $this->settings->security()->requiresStrongPassword()
            ? ['required', 'confirmed', Password::min(8)->letters()->numbers()->symbols()]
            : ['required', 'confirmed', 'string', 'min:8', 'max:100'];

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => $rules,
        ]);

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
        $user = $this->user($request);
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

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
