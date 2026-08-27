<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

uses(DatabaseTruncation::class);

it('creates a client-credentials client, associates its owner and sets a rate limit', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->artisan('crm:oauth-client:create', [
        'name' => 'n8n integration',
        '--owner' => 'owner@example.com',
        '--rate-limit' => '100',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Client created:')
        ->expectsOutputToContain('shown once');

    $client = Client::query()->where('name', 'n8n integration')->firstOrFail();

    expect($client->owner_id)->toBe($owner->id)
        ->and($client->owner_type)->toBe(User::class)
        ->and($client->rate_limit_per_minute)->toBe(100)
        ->and($client->revoked)->toBeFalse();
});

it('creates a client with no owner or rate limit when neither is given', function () {
    $this->artisan('crm:oauth-client:create', ['name' => 'bare client'])->assertExitCode(0);

    $client = Client::query()->where('name', 'bare client')->firstOrFail();

    expect($client->owner_id)->toBeNull()
        ->and($client->rate_limit_per_minute)->toBeNull();
});

it('fails to create a client for an unknown owner email', function () {
    $this->artisan('crm:oauth-client:create', ['name' => 'x', '--owner' => 'nobody@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain('No user found');

    expect(Client::query()->where('name', 'x')->exists())->toBeFalse();
});

it('lists clients with their owner, rate limit and revoked status', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('n8n');
    $client->owner()->associate($owner);
    $client->rate_limit_per_minute = 50;
    $client->save();

    $this->artisan('crm:oauth-client:list')
        ->assertExitCode(0)
        ->expectsTable(
            ['ID', 'Name', 'Owner', 'Rate limit/min', 'Revoked'],
            [[$client->id, 'n8n', 'owner@example.com', 50, 'no']],
        );
});

it('revokes a client so it no longer authenticates', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('to revoke');

    $this->artisan('crm:oauth-client:revoke', ['id' => $client->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('revoked');

    expect(app(ClientRepository::class)->findActive($client->id))->toBeNull();
});

it('fails to revoke an unknown client id', function () {
    $this->artisan('crm:oauth-client:revoke', ['id' => (string) Str::uuid()])
        ->assertExitCode(1)
        ->expectsOutputToContain('No client found');
});

it('sets and clears a client\'s rate limit', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('rate limited');

    $this->artisan('crm:oauth-client:set-rate-limit', ['id' => $client->id, 'limit' => '30'])
        ->assertExitCode(0);
    expect($client->fresh()->rate_limit_per_minute)->toBe(30);

    $this->artisan('crm:oauth-client:set-rate-limit', ['id' => $client->id, 'limit' => '0'])
        ->assertExitCode(0)
        ->expectsOutputToContain('cleared');
    expect($client->fresh()->rate_limit_per_minute)->toBeNull();
});
