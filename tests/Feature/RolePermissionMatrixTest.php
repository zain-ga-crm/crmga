<?php

use App\Filament\Pages\RolePermissionMatrix;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\User;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-4.6: the matrix page over the already-existing Role/RoleModulePermission/
// Acl backend. Rows come from Acl::registerRole()'s auto-backfill (fired by
// Role::created in AppServiceProvider) -- this page only reads/updates those
// rows and defensively re-syncs on mount in case a module was added since.
uses(DatabaseTruncation::class);

beforeEach(fn () => promotePrimaryTenant());

it('denies the page to a non-admin user', function () {
    $this->actingAs(User::factory()->create());

    $this->get(RolePermissionMatrix::getUrl())->assertForbidden();
});

it('allows the page to an admin user and defaults to the first role alphabetically', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Role::factory()->create(['name' => 'Zebra Role']);
    $first = Role::factory()->create(['name' => 'Alpha Role']);

    Livewire::test(RolePermissionMatrix::class)
        ->assertSuccessful()
        ->assertSet('roleId', $first->id);
});

it('deep-links to a specific role via the ?role= query parameter', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Role::factory()->create();
    $target = Role::factory()->create();

    Livewire::test(RolePermissionMatrix::class, ['roleId' => $target->id])
        ->assertSet('roleId', $target->id);
});

it('lists one row per module for the selected role, backfilling any missing rows on mount', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $module = Module::factory()->create(['key' => 'leads', 'label' => 'Leads']);
    $role = Role::factory()->create();
    // Simulate a module that was added after this role -- registerModule()
    // would normally have backfilled it, but this proves the page's own
    // defensive re-sync covers the gap too.
    RoleModulePermission::query()->where('role_id', $role->id)->where('module_key', 'leads')->delete();

    Livewire::test(RolePermissionMatrix::class, ['roleId' => $role->id])
        ->assertCanSeeTableRecords(
            RoleModulePermission::query()->where('role_id', $role->id)->where('module_key', $module->key)->get()
        );
});

it('updates one action for one module through the inline select column', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Module::factory()->create(['key' => 'leads']);
    $role = Role::factory()->create();
    $row = RoleModulePermission::query()->where('role_id', $role->id)->where('module_key', 'leads')->firstOrFail();

    Livewire::test(RolePermissionMatrix::class, ['roleId' => $role->id])
        ->call('updateTableColumnState', 'view', $row->getKey(), 'all');

    expect($row->fresh()->view)->toBe(AccessLevel::All);
});

it('sets every action on one row via the row action', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Module::factory()->create(['key' => 'leads']);
    $role = Role::factory()->create();
    $row = RoleModulePermission::query()->where('role_id', $role->id)->where('module_key', 'leads')->firstOrFail();

    Livewire::test(RolePermissionMatrix::class, ['roleId' => $role->id])
        ->callTableAction('setRow', $row, data: ['level' => 'owner']);

    $row->refresh();
    foreach (Acl::ACTIONS as $action) {
        expect($row->getAttribute($action))->toBe(AccessLevel::Owner);
    }
});

it('sets one action across every module via the set-a-column header action', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Module::factory()->create(['key' => 'leads']);
    Module::factory()->create(['key' => 'companies']);
    $role = Role::factory()->create();

    Livewire::test(RolePermissionMatrix::class, ['roleId' => $role->id])
        ->callTableAction('setColumn', data: ['action' => 'export', 'level' => 'group']);

    $rows = RoleModulePermission::query()->where('role_id', $role->id)->get();
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row->export)->toBe(AccessLevel::Group);
    }
});
