<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * S-1.2 (crmga_Frontend_Design_Spec.docx §7): sign-in plus the two-factor
 * challenge, as one page with two steps rather than a second route -- credentials
 * are verified WITHOUT logging the user in (Filament::auth()->validate(), not
 * ->attempt()) whenever 2FA is enabled, so there is never a real session to
 * "undo" while the code is still pending. The pending user id lives in the
 * session for the one extra request the challenge step needs.
 *
 * Deliberate simplification vs the spec's separate "Use a backup code" link:
 * one code field accepts either a 6-digit authenticator code or a backup
 * code, distinguished by shape (BACKEND_BRIEF has no format for backup codes
 * that collides with a 6-digit TOTP code) -- avoids a second Livewire toggle
 * action for a rarely-used path.
 */
class Login extends \Filament\Pages\Auth\Login
{
    public string $step = 'credentials';

    protected function getForms(): array
    {
        if ($this->step === 'challenge') {
            return [
                'form' => $this->form(
                    $this->makeForm()
                        ->schema([$this->getChallengeCodeFormComponent()])
                        ->statePath('data'),
                ),
            ];
        }

        return parent::getForms();
    }

    public function authenticate(): ?LoginResponse
    {
        if ($this->step === 'challenge') {
            return $this->completeChallenge();
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        if (! Filament::auth()->validate($credentials)) {
            $this->throwFailureValidationException();
        }

        /** @var User $user */
        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        $panel = Filament::getCurrentPanel();
        if ($panel !== null && ! $user->canAccessPanel($panel)) {
            $this->throwFailureValidationException();
        }

        if ($user->hasTwoFactorEnabled()) {
            session([
                'login.pending_user_id' => $user->id,
                'login.pending_remember' => (bool) ($data['remember'] ?? false),
            ]);
            $this->step = 'challenge';
            $this->form->fill();

            return null;
        }

        Filament::auth()->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();

        return app(LoginResponse::class);
    }

    private function completeChallenge(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $userId = session('login.pending_user_id');
        $user = is_string($userId) ? User::query()->find($userId) : null;

        if ($user === null) {
            // The session entry is gone (expired, or never set) -- there is
            // nothing to challenge; send them back to credentials rather
            // than fail on a field that no longer means anything.
            $this->cancelChallenge();

            return null;
        }

        $state = $this->form->getState();
        $code = $state['code'] ?? null;

        if (! is_string($code)) {
            throw ValidationException::withMessages([
                'data.code' => __('The provided code is invalid.'),
            ]);
        }

        $verified = preg_match('/^\d{6}$/', $code) === 1
            ? $user->verifyTwoFactorCode($code)
            : $user->useRecoveryCode($code);

        if (! $verified) {
            throw ValidationException::withMessages([
                'data.code' => __('The provided code is invalid.'),
            ]);
        }

        $remember = (bool) session('login.pending_remember', false);
        session()->forget(['login.pending_user_id', 'login.pending_remember']);

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function cancelChallenge(): void
    {
        session()->forget(['login.pending_user_id', 'login.pending_remember']);
        $this->step = 'credentials';
        $this->form->fill();
    }

    protected function getChallengeCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label(__('Authentication code'))
            ->helperText(__('Enter the 6-digit code from your authenticator app, or one of your backup codes.'))
            ->required()
            ->autofocus()
            ->autocomplete('one-time-code')
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        if ($this->step === 'challenge') {
            return [
                $this->getAuthenticateFormAction()->label(__('Verify')),
                Action::make('cancelChallenge')
                    ->label(__('Back to sign in'))
                    ->link()
                    ->action('cancelChallenge'),
            ];
        }

        return parent::getFormActions();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->step === 'challenge' ? 'Two-factor authentication' : parent::getTitle();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->step === 'challenge' ? 'Two-factor authentication' : parent::getHeading();
    }
}
