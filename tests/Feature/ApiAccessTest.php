<?php

use App\Filament\Pages\ApiClients;
use App\Filament\Pages\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Livewire\Livewire;

// S-5.1: API client/personal-token management. The backend (Passport,
// ApiScopes, OAuthClientRevokeCommand) already existed in full -- these are
// the two screens that were missing: personal access tokens on the Security
// page (every user's own account, same audience as 2FA), and server-to-server
// OAuth clients on the admin-only ApiClients page.
uses(DatabaseTruncation::class);

beforeEach(function () {
    // $this->get() (unlike Livewire::test()) goes through real HTTP/domain
    // routing -- Z-8.2's tenancy middleware 404s on an unresolved domain
    // without this.
    promotePrimaryTenant();

    // $user->createToken() looks up Passport's "personal access client" row
    // by grant type -- a real prerequisite (normally created once via
    // `passport:client --personal`), not something these tests fake away.
    app(ClientRepository::class)->createPersonalAccessGrantClient('Testing personal access client');
});

it('creates a personal access token, showing the plaintext value once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->fillForm(['name' => 'My script', 'scopes' => ['leads:read']], 'tokenForm')
        ->call('createToken');

    expect($component->get('pendingPlainTextToken'))->not->toBeNull();

    $token = $user->tokens()->where('name', 'My script')->first();
    expect($token)->not->toBeNull()
        ->and($token->scopes)->toBe(['leads:read'])
        ->and($token->revoked)->toBeFalse();
});

it('lists only the signed-in user\'s own tokens, not another user\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->createToken('Mine');
    $other->createToken('Theirs');
    $this->actingAs($user);

    $names = Livewire::test(Security::class)->instance()->tokens()->pluck('name');

    expect($names)->toContain('Mine')->not->toContain('Theirs');
});

it('revokes a personal access token', function () {
    $user = User::factory()->create();
    $result = $user->createToken('My script');
    $this->actingAs($user);

    Livewire::test(Security::class)->call('revokeToken', $result->token->id);

    expect($result->token->fresh()->revoked)->toBeTrue();
});

it('denies the API Clients page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(ApiClients::getUrl())->assertForbidden();
});

it('creates a client-credentials OAuth client as an admin, showing the secret once', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ApiClients::class)
        ->callTableAction('create', data: ['name' => 'n8n integration'])
        ->assertHasNoTableActionErrors();

    $client = Client::query()->where('name', 'n8n integration')->first();
    expect($client)->not->toBeNull()
        ->and($client->hasGrantType('client_credentials'))->toBeTrue()
        ->and($client->revoked)->toBeFalse();
});

it('revokes and unrevokes an OAuth client', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Test client');
    $this->actingAs($admin);

    Livewire::test(ApiClients::class)->callTableAction('revoke', $client);
    expect($client->fresh()->revoked)->toBeTrue();

    Livewire::test(ApiClients::class)->callTableAction('revoke', $client);
    expect($client->fresh()->revoked)->toBeFalse();
});
