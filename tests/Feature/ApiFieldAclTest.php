<?php

use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\Acl\FieldAccess;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;

// Z-8.3 -- DatabaseTruncation, not RefreshDatabase (see ApiAclTest.php).
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('hides a field marked hidden for the caller\'s role from the show response', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantFieldAccess($user, 'leads', 'primary_email', FieldAccess::Hidden);
    $lead = Lead::factory()->create(['primary_email' => 'amina@example.com']);

    actingAsApiUser($user, ['leads:read']);

    $this->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.attributes.primary_email')
        ->assertJsonPath('data.attributes.stage', 'new');
});

it('hides a field marked hidden for the caller\'s role from the index response', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantFieldAccess($user, 'leads', 'primary_email', FieldAccess::Hidden);
    Lead::factory()->create(['primary_email' => 'amina@example.com']);

    actingAsApiUser($user, ['leads:read']);

    $this->getJson('/api/v1/leads')
        ->assertOk()
        ->assertJsonMissingPath('data.0.attributes.primary_email');
});

it('lets a field through unchanged when no field permission row exists', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    $lead = Lead::factory()->create(['primary_email' => 'amina@example.com']);

    actingAsApiUser($user, ['leads:read']);

    $this->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('data.attributes.primary_email', 'amina@example.com');
});

it('drops a read_only field from a create request instead of erroring', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'leads', 'source', FieldAccess::ReadOnly);
    actingAsApiUser($user, ['leads:write']);

    $response = $this->postJson('/api/v1/leads', ['full_name' => 'Amina Khan', 'source' => 'meta'])
        ->assertStatus(201);

    $lead = Lead::withoutGlobalScopes()->find($response->json('data.id'));
    expect($lead->source)->toBeNull();
});

it('drops a hidden field from an update request instead of erroring', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantAccess($user, 'leads', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'leads', 'source', FieldAccess::Hidden);
    $lead = Lead::factory()->create(['source' => 'manual']);

    actingAsApiUser($user, ['leads:read', 'leads:write']);

    $this->patchJson("/api/v1/leads/{$lead->id}", ['source' => 'meta'])->assertOk();

    expect($lead->fresh()->source)->toBe('manual');
});

it('combines two roles to the most permissive field access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantFieldAccess($user, 'leads', 'primary_email', FieldAccess::Hidden);
    grantFieldAccess($user, 'leads', 'primary_email', FieldAccess::ReadWrite);
    $lead = Lead::factory()->create(['primary_email' => 'amina@example.com']);

    actingAsApiUser($user, ['leads:read']);

    $this->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('data.attributes.primary_email', 'amina@example.com');
});
