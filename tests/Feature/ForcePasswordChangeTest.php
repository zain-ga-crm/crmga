<?php

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

// S-1.2's forced first-login step (crmga_Frontend_Design_Spec.docx §7).
// Z-8.3 -- DatabaseTruncation, not RefreshDatabase (see ApiAclTest.php);
// promotePrimaryTenant() is required before any real HTTP request into the
// panel, since its routes sit behind InitializeTenancyByDomain.
uses(DatabaseTruncation::class);

beforeEach(fn () => promotePrimaryTenant());

it('redirects a user who must change their password away from the dashboard', function () {
    $user = User::factory()->mustChangePassword()->create();
    $this->actingAs($user);

    $this->get('/admin')->assertRedirect(ChangePassword::getUrl());
});

it('does not redirect a user who has already chosen their own password', function () {
    $user = User::factory()->create(); // password_changed_at set by the factory's default state
    $this->actingAs($user);

    $this->get('/admin')->assertOk();
});

it('lets the forced user reach the change-password page itself, without a redirect loop', function () {
    $user = User::factory()->mustChangePassword()->create();
    $this->actingAs($user);

    $this->get(ChangePassword::getUrl())->assertOk();
});

it('changes the password and clears the forced-change flag', function () {
    $user = User::factory()->mustChangePassword()->create();
    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'password' => 'Str0ng-Pass!word12',
            'password_confirmation' => 'Str0ng-Pass!word12',
        ])
        ->call('save');

    $fresh = $user->fresh();
    expect($fresh->mustChangePassword())->toBeFalse()
        ->and(Hash::check('Str0ng-Pass!word12', $fresh->password))->toBeTrue();
});

it('rejects a password that fails the password policy', function () {
    $user = User::factory()->mustChangePassword()->create();
    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm(['password' => 'weak', 'password_confirmation' => 'weak'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->fresh()->mustChangePassword())->toBeTrue();
});
