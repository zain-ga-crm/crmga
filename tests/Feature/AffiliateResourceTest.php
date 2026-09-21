<?php

use App\Filament\Exports\AffiliateExporter;
use App\Filament\Resources\AffiliateResource;
use App\Filament\Resources\AffiliateResource\Pages\CreateAffiliate;
use App\Filament\Resources\AffiliateResource\Pages\ListAffiliates;
use App\Models\Affiliate;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.1: AffiliateResource is another DynamicResource -- coverage
// deliberately mirrors CompanyResourceTest rather than re-deriving it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists affiliates with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $affiliates = Affiliate::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListAffiliates::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($affiliates)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('username')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the affiliates list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'affiliates', AccessLevel::Owner, 'list');
    grantAccess($user, 'affiliates', AccessLevel::Owner, 'view');

    $own = Affiliate::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Affiliate::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListAffiliates::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no affiliates access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(AffiliateResource::getUrl('index'))->assertForbidden();
});

it('creates an affiliate end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'first_name' => 'Leo',
            'last_name' => 'Tan',
            'primary_email' => 'leo@example.test',
            'username' => 'leotan',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Affiliate::query()->where('primary_email', 'leo@example.test')->exists())->toBeTrue();
});

it('exports affiliates using the same list-layout columns the table shows', function () {
    $columns = collect(AffiliateExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('full_name', 'username', 'status', 'primary_email', 'assignedUser.name');
});
