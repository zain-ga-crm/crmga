<?php

use App\Filament\Pages\DoNotCallList;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.5: the dedicated Do Not Call view. Its own scoping (which modules are
// selectable, which records are visible) is thin wiring over
// ContactableModuleRegistry + Acl::effective() -- the do_not_call filtering
// itself is BuildsResourceFromMetadata's job, already covered elsewhere.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('allows the page to any authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(DoNotCallList::getUrl())->assertSuccessful();
});

it('lists do_not_call leads for a user with All access, defaulting to the first visible module', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $dnc = Lead::factory()->create(['do_not_call' => true]);
    Lead::factory()->create(['do_not_call' => false]);

    $this->actingAs($admin);

    Livewire::test(DoNotCallList::class)
        ->set('moduleKey', 'leads')
        ->assertCanSeeTableRecords([$dnc]);
});

it('switches to another module and shows its own do_not_call records', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $dncCompany = Company::factory()->create(['do_not_call' => true]);
    $dncLead = Lead::factory()->create(['do_not_call' => true]);

    $this->actingAs($admin);

    Livewire::test(DoNotCallList::class)
        ->set('moduleKey', 'companies')
        ->assertCanSeeTableRecords([$dncCompany])
        ->assertCanNotSeeTableRecords([$dncLead]);
});

it('only offers modules the user has list access to', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');

    $this->actingAs($user);

    $options = Livewire::test(DoNotCallList::class)->instance()->moduleOptions();

    expect($options)->toHaveKey('leads')
        ->and($options)->not->toHaveKey('companies');
});

it('scopes visible do_not_call records to an Owner-access user\'s own records', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::Owner, 'list');
    grantAccess($user, 'leads', AccessLevel::Owner, 'view');

    $own = Lead::factory()->create(['do_not_call' => true, 'assigned_user_id' => $user->id]);
    $someoneElses = Lead::factory()->create(['do_not_call' => true]);

    $this->actingAs($user);

    Livewire::test(DoNotCallList::class)
        ->set('moduleKey', 'leads')
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});
