<?php

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;

// S-4.7: global search. Wired generically in BuildsResourceFromMetadata
// (one override serves all six Contactable resources) rather than per
// resource -- canGloballySearch() already gates on canAccess() (module
// enabled + canViewAny()) and the query inherits AppliesRecordAccess'
// global scope the same as every other query against these models, so
// "permitted modules" needed no new scoping of its own.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('finds a lead by email and titles the result with its full name', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $lead = Lead::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'primary_email' => 'jane.doe@example.test']);

    $results = LeadResource::getGlobalSearchResults('jane.doe@example.test');

    expect($results)->toHaveCount(1);
    $result = $results->first();
    expect($result->title)->toBe('Jane Doe')
        ->and($result->url)->toBe(LeadResource::getUrl('edit', ['record' => $lead]))
        ->and($result->details)->toHaveKey('Email', 'jane.doe@example.test');
});

it('finds a lead by phone number', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Lead::factory()->create(['phone_mobile' => '+1 555-9123']);

    expect(LeadResource::getGlobalSearchResults('555-9123'))->toHaveCount(1);
});

it('scopes search results to what the signed-in user is allowed to see, same as the list table', function () {
    $owner = User::factory()->create();
    grantAccess($owner, 'leads', AccessLevel::Owner);

    $mine = Lead::factory()->create(['assigned_user_id' => $owner->id, 'first_name' => 'Owned', 'primary_email' => 'shared-term@example.test']);
    Lead::factory()->create(['assigned_user_id' => User::factory()->create()->id, 'first_name' => 'NotOwned', 'primary_email' => 'shared-term@example.test']);

    $this->actingAs($owner);

    $results = LeadResource::getGlobalSearchResults('shared-term@example.test');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Owned '.$mine->last_name);
});

it('excludes a whole module from global search when the user has no access to it at all', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(LeadResource::canGloballySearch())->toBeFalse();
});

it('includes a module in global search once the user has access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'companies', AccessLevel::All, 'list');
    $this->actingAs($user);

    expect(CompanyResource::canGloballySearch())->toBeTrue();
});
