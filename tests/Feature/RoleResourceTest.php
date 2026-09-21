<?php

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-4.6: role CRUD. Role::created firing Acl::registerRole() (the matrix
// backfill) is already covered by AclTest.php-style coverage elsewhere --
// this file is only about the resource wiring: the admin gate and the
// system-role protections (no rename, no delete).
uses(DatabaseTruncation::class);

beforeEach(fn () => promotePrimaryTenant());

it('denies the resource to a non-admin user', function () {
    $this->actingAs(User::factory()->create());

    $this->get(RoleResource::getUrl('index'))->assertForbidden();
});

it('allows the resource to an admin user', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(RoleResource::getUrl('index'))->assertSuccessful();
});

it('creates a role, which backfills a permission row for every module', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Module::factory()->create(['key' => 'leads']);

    Livewire::test(RoleResource\Pages\CreateRole::class)
        ->fillForm(['name' => 'Field Agent', 'description' => 'Regional field staff'])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Field Agent')->firstOrFail();
    expect($role->permissions)->not->toBeEmpty();
});

it('disables the name field on the system role\'s edit form', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $admin = Role::factory()->create(['name' => 'Administrator', 'is_system' => true]);

    Livewire::test(EditRole::class, ['record' => $admin->getRouteKey()])
        ->assertFormFieldIsDisabled('name');
});

it('hides the delete action for the system role but not for an ordinary one', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $admin = Role::factory()->create(['is_system' => true]);
    $ordinary = Role::factory()->create(['is_system' => false]);

    Livewire::test(RoleResource\Pages\ListRoles::class)
        ->assertTableActionHidden('delete', $admin)
        ->assertTableActionVisible('delete', $ordinary);
});
