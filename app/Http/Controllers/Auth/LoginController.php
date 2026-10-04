<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\LoginNotice;
use App\Services\Security\AuthenticationService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-in for both panels.
 *
 * One controller handles both so the throttling, captcha and session handling
 * cannot differ between them - in the legacy app the admin and reseller logins
 * were separate copies and only one of them regenerated the session.
 */
class LoginController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly SettingsService $settings,
    ) {}

    public function show(Request $request, string $panel = 'reseller'): View|RedirectResponse
    {
        $guard = $panel === 'admin' ? 'admin' : 'reseller';

        if (auth()->guard($guard)->check()) {
            return redirect()->route($panel === 'admin' ? 'admin.dashboard' : 'reseller.dashboard');
        }

        $company = $this->settings->company();
        $captchaRequired = $this->captchaRequired($panel);
        [$question, $token] = $captchaRequired ? $this->makeCaptcha() : [null, null];

        return view('auth.login', [
            'panel'           => $panel,
            'brandName'       => $company->displayName(),
            'brandInitials'   => Str::upper(Str::substr($company->displayName(), 0, 2)),
            'captchaRequired' => $captchaRequired,
            'captchaQuestion' => $question,
            'captchaToken'    => $token,
            'supportContact'  => $company->helpdesk_mobile,
            'loginNotice'     => $this->loginNotice(),
        ]);
    }

    public function store(Request $request, string $panel = 'reseller'): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        if ($this->captchaRequired($panel)) {
            $this->verifyCaptcha($request);
        }

        [$ok, $error, $user] = $this->auth->attempt(
            $request,
            $credentials['username'],
            $credentials['password'],
            $panel,
        );

        if (! $ok) {
            // Never echo the submitted password back into the form.
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $error]);
        }

        $home = $panel === 'admin' ? 'admin.dashboard' : 'reseller.dashboard';

        return redirect()->intended(route($home));
    }

    public function destroy(Request $request, string $panel = 'reseller'): RedirectResponse
    {
        $this->auth->logout($request, $panel);

        return redirect()
            ->route($panel === 'admin' ? 'admin.login' : 'reseller.login')
            ->with('status', 'You have been signed out.');
    }

    private function captchaRequired(string $panel): bool
    {
        $security = $this->settings->security();

        return $panel === 'admin'
            ? $security->captchaRequiredForAdmin()
            : $security->captchaRequiredForReseller();
    }

    /**
     * A small arithmetic challenge, with the answer carried in an encrypted,
     * timestamped token rather than in the session.
     *
     * Keeping the answer out of the session means the challenge still works
     * across a load-balanced deployment, and encrypting it means the client
     * cannot read or forge it. The timestamp bounds how long a solved token
     * stays replayable.
     */
    private function makeCaptcha(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);

        return [
            "{$a} + {$b}",
            Crypt::encryptString(json_encode(['answer' => $a + $b, 'at' => time()])),
        ];
    }

    private function verifyCaptcha(Request $request): void
    {
        $fail = fn () => throw ValidationException::withMessages([
            'username' => 'The security check was incorrect. Please try again.',
        ]);

        $token = (string) $request->input('captcha_token');
        $given = trim((string) $request->input('captcha'));

        if ($token === '' || $given === '') {
            $fail();
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $fail();
        }

        $age = time() - (int) ($payload['at'] ?? 0);

        // Expire the challenge so a captured token cannot be reused later.
        if ($age < 0 || $age > 600) {
            $fail();
        }

        if ((string) ($payload['answer'] ?? '') !== $given) {
            $fail();
        }
    }

    private function loginNotice(): ?string
    {
        $notice = LoginNotice::query()->orderByDesc('id')->value('lnotice');

        // Operator-authored HTML, so strip it down to safe inline markup.
        return filled($notice) ? strip_tags((string) $notice, '<b><strong><i><em><br><a>') : null;
    }
}
