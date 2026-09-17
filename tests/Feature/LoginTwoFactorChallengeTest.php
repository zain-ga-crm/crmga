<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

// S-1.2 (crmga_Frontend_Design_Spec.docx §7): sign-in plus the two-factor
// challenge as one page, two steps -- see Login's own docblock for why
// credentials are verified without ever establishing a session (auth()
// stays a guest) until a pending 2FA challenge is cleared.
uses(DatabaseTruncation::class);

function currentTotpFor(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret);
}

it('logs in directly when the user has no two-factor enabled', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});

it('rejects wrong credentials without ever reaching the challenge', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth()->check())->toBeFalse()
        ->and(session('login.pending_user_id'))->toBeNull();
});

it('shows the two-factor challenge instead of logging in directly when 2FA is enabled', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeFalse()
        ->and($component->get('step'))->toBe('challenge')
        ->and(session('login.pending_user_id'))->toBe($user->id);
});

it('completes login with a valid authenticator code at the challenge step', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->fillForm(['code' => currentTotpFor($user)])
        ->call('authenticate');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id)
        ->and(session('login.pending_user_id'))->toBeNull();
});

it('completes login with a valid backup code, consuming it', function () {
    $user = User::factory()->create();
    $codes = $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));
    $backupCode = $codes[0];

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->fillForm(['code' => $backupCode])
        ->call('authenticate');

    expect(auth()->check())->toBeTrue()
        ->and($user->fresh()->useRecoveryCode($backupCode))->toBeFalse(); // already consumed
});

it('rejects an invalid code at the challenge step without logging in', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->fillForm(['code' => '000000'])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);

    expect(auth()->check())->toBeFalse();
});

it('cancelling the challenge returns to the credentials step and clears the pending session', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(currentTotpFor($user));

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->call('cancelChallenge');

    expect($component->get('step'))->toBe('credentials')
        ->and(session('login.pending_user_id'))->toBeNull();
});
