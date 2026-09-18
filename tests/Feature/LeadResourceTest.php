<?php

use App\Enums\LeadVertical;
use App\Filament\Exports\LeadExporter;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\Acl\FieldAccess;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-2.1: LeadResource is the first real DynamicResource -- everything it does
// (form, table, infolist, filters, ACL) comes from BuildsResourceFromMetadata
// reading the 'leads' module's own tenant_fields/tenant_layouts, so these
// tests exercise the mechanism through its first real consumer rather than
// unit-testing the trait's build methods in isolation (which would need a
// bare, unmounted Form/Table/Infolist with no record context -- the exact
// evaluate() pitfall FieldTypeRegistryTest's own comments already document).
uses(DatabaseTruncation::class);

beforeEach(function () {
    // MetadataRepository::compiled() is cached forever, keyed by version --
    // the cache store is 'database' (persists across test processes, unlike
    // an array store), so a stale compile from an earlier run/file can leak
    // in here. MetadataRepositoryTest.php flushes for the same reason.
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists leads with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $leads = Lead::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListLeads::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($leads)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('vertical')
        ->assertTableColumnExists('stage')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('phone_mobile')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the leads list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::Owner, 'list');
    grantAccess($user, 'leads', AccessLevel::Owner, 'view');

    $own = Lead::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Lead::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no leads access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(LeadResource::getUrl('index'))->assertForbidden();
});

it('sorts by the list layout\'s default_sort', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $older = Lead::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = Lead::factory()->create(['created_at' => now()]);

    $this->actingAs($admin);

    // leadsListLayout() sets default_sort to created_at desc.
    Livewire::test(ListLeads::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('hides a field-level-hidden field from the create form', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');
    grantAccess($user, 'leads', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'leads', 'source', FieldAccess::Hidden);

    $this->actingAs($user);

    Livewire::test(CreateLead::class)
        ->assertFormFieldDoesNotExist('source')
        ->assertFormFieldExists('first_name');
});

it('disables a field-level-read-only field on the edit form', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');
    grantAccess($user, 'leads', AccessLevel::All, 'view');
    grantAccess($user, 'leads', AccessLevel::All, 'edit');
    grantFieldAccess($user, 'leads', 'source', FieldAccess::ReadOnly);

    $lead = Lead::factory()->create();
    $this->actingAs($user);

    Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
        ->assertFormFieldIsDisabled('source');
});

it('denies editing to a user with only view access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');
    grantAccess($user, 'leads', AccessLevel::All, 'view');

    $lead = Lead::factory()->create();
    $this->actingAs($user);

    $this->get(LeadResource::getUrl('edit', ['record' => $lead]))->assertForbidden();
});

it('shows the detail view via infolist with real field values', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $lead = Lead::factory()->create(['primary_email' => 'someone@example.com']);

    $this->actingAs($admin);

    $this->get(LeadResource::getUrl('view', ['record' => $lead]))
        ->assertSuccessful()
        ->assertSee('someone@example.com');
});

it('shows a visible_when panel\'s content on the detail view when its condition matches', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $lead = Lead::factory()->create(['vertical' => LeadVertical::Refugee, 'source' => 'Referral programme XYZ']);

    $this->actingAs($admin);

    $this->get(LeadResource::getUrl('view', ['record' => $lead]))
        ->assertSuccessful()
        ->assertSee('Referral programme XYZ')
        ->assertSee('Overview')
        ->assertSee('Flags');
});

it('hides a visible_when panel\'s content on the detail view when its condition does not match', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $lead = Lead::factory()->create(['vertical' => LeadVertical::ExpressEntry, 'source' => 'Referral programme XYZ']);

    $this->actingAs($admin);

    $this->get(LeadResource::getUrl('view', ['record' => $lead]))
        ->assertSuccessful()
        ->assertDontSee('Referral programme XYZ');
});

it('suppresses the phone click-to-call link on a do_not_call lead in the list, per S-1.4', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    Lead::factory()->create(['phone_mobile' => '+1 (416) 555-0134', 'do_not_call' => true]);

    $this->actingAs($admin);

    $this->get(LeadResource::getUrl('index'))
        ->assertSuccessful()
        ->assertDontSee('tel:+14165550134', false);
});

it('keeps the phone click-to-call link for a reachable lead in the list', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    Lead::factory()->create(['phone_mobile' => '+1 (416) 555-0134', 'do_not_call' => false]);

    $this->actingAs($admin);

    $this->get(LeadResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('tel:+14165550134', false);
});

// S-2.4: bulk-action bar (delete/export) + header export -- gated on the
// same ACL actions as everything else in BuildsResourceFromMetadata.

it('shows the delete bulk action for a user with delete access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');
    grantAccess($user, 'leads', AccessLevel::All, 'delete');

    $this->actingAs($user);

    Livewire::test(ListLeads::class)->assertTableBulkActionExists('delete');
});

it('hides the delete bulk action for a user without delete access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');

    $this->actingAs($user);

    Livewire::test(ListLeads::class)->assertTableBulkActionDoesNotExist('delete');
});

it('shows the export bulk action and header action for a user with export access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');
    grantAccess($user, 'leads', AccessLevel::All, 'export');

    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->assertTableBulkActionExists('export')
        ->assertActionExists('export');
});

it('hides the export bulk action and header action for a user without export access', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::All, 'list');

    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->assertTableBulkActionDoesNotExist('export')
        ->assertActionDoesNotExist('export');
});

it('bulk-deletes only the selected leads', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $toDelete = Lead::factory()->create();
    $toKeep = Lead::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ListLeads::class)
        ->callTableBulkAction('delete', [$toDelete->id]);

    expect(Lead::query()->find($toDelete->id))->toBeNull()
        ->and(Lead::query()->find($toKeep->id))->not->toBeNull();
});

it('exports leads using the same list-layout columns the table shows', function () {
    $columns = collect(LeadExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('full_name', 'vertical', 'stage', 'primary_email', 'phone_mobile', 'assignedUser.name');
});
