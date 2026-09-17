<?php

use App\Filament\Pages\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

// S-1.2's optional two-factor enrolment (crmga_Frontend_Design_Spec.docx §7)
// -- built as its own page rather than a second step of ChangePassword's
// wizard, see Security's own docblock for why.
uses(DatabaseTruncation::class);

it('starts enrolment, generating a secret and recovery codes shown once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Security::class)->call('startEnrolling');

    expect($user->fresh()->two_factor_secret)->not->toBeNull()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse() // not confirmed yet
        ->and($component->get('pendingRecoveryCodes'))->toHaveCount(8)
        ->and($component->get('isEnrolling'))->toBeTrue();
});

it('confirms enrolment with a valid authenticator code', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Security::class)->call('startEnrolling');
    $validCode = app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret);

    $component->fillForm(['code' => $validCode])->call('confirm');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejects an invalid code and does not confirm enrolment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Security::class)->call('startEnrolling');
    $component->fillForm(['code' => '000000'])->call('confirm');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('disables two-factor authentication', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->confirmTwoFactor(app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret));
    $this->actingAs($user);

    Livewire::test(Security::class)->call('disable');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});
