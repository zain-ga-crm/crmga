<?php

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\Acl\FieldAccess;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-2.2: CompanyResource is the second real DynamicResource, proving
// BuildsResourceFromMetadata (S-2.1) generalises past leads -- everything
// here comes from the 'companies' module's own tenant_fields/tenant_layouts.
// Coverage deliberately mirrors LeadResourceTest rather than re-deriving it
// from scratch, since it's exercising the same shared mechanism.
uses(DatabaseTruncation::class);

beforeEach(function () {
    // MetadataRepository::compiled() is cached forever, keyed by version --
    // the cache store persists across test processes (see
    // MetadataRepositoryTest.php's own beforeEach for the same reason).
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists companies with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $companies = Company::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListCompanies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($companies)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('industry')
        ->assertTableColumnExists('company_contact_status')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('contact_person_phone')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the companies list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'companies', AccessLevel::Owner, 'list');
    grantAccess($user, 'companies', AccessLevel::Owner, 'view');

    $own = Company::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Company::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no companies access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(CompanyResource::getUrl('index'))->assertForbidden();
});

it('gives a named boolean field a colored badge on the list, same BadgeRegistry as leads', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    Company::factory()->create(['hot_lead' => true]);

    $this->actingAs($admin);

    $this->get(CompanyResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Hot');
});

it('hides a field-level-hidden field from the create form', function () {
    $user = User::factory()->create();
    // Every resource page -- not just the list page -- gates through
    // Resource::canAccess(), which defaults to canViewAny() ('list'). 'edit'
    // alone authorizes the create action itself but never reaches it.
    grantAccess($user, 'companies', AccessLevel::All, 'list');
    grantAccess($user, 'companies', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'companies', 'lmia', FieldAccess::Hidden);

    $this->actingAs($user);

    Livewire::test(CreateCompany::class)
        ->assertFormFieldDoesNotExist('lmia')
        ->assertFormFieldExists('first_name');
});

it('disables a field-level-read-only field on the edit form', function () {
    $user = User::factory()->create();
    grantAccess($user, 'companies', AccessLevel::All, 'list');
    grantAccess($user, 'companies', AccessLevel::All, 'view');
    grantAccess($user, 'companies', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'companies', 'industry', FieldAccess::ReadOnly);

    $company = Company::factory()->create();
    $this->actingAs($user);

    Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
        ->assertFormFieldIsDisabled('industry');
});

it('shows the detail view via infolist with real field values', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $company = Company::factory()->create(['primary_email' => 'contact@acme.example']);

    $this->actingAs($admin);

    $this->get(CompanyResource::getUrl('view', ['record' => $company]))
        ->assertSuccessful()
        ->assertSee('contact@acme.example');
});

it('creates a company end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateCompany::class)
        ->fillForm([
            'first_name' => 'Acme',
            'last_name' => 'Immigration',
            'primary_email' => 'hello@acme.example',
            'industry' => 'Consulting',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('primary_email', 'hello@acme.example')->exists())->toBeTrue();
});
