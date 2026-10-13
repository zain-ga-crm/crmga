<?php

use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Gate;

// Z-7.1 (security review): Role/User/Module/Field had no Laravel Policy at
// all before this -- authorization on them was page-level only
// (RoleResource/UserResource/Studio pages' own canAccess() => isAdmin()).
// This is the defense-in-depth layer the framework's own Gate checks now
// enforce too, not a behaviour change for the Filament pages themselves.
uses(DatabaseTruncation::class);

it('resolves a dedicated policy for each of the four previously-unguarded models', function () {
    foreach ([Role::class, User::class, Module::class, Field::class] as $model) {
        expect(Gate::getPolicyFor($model))->not->toBeNull();
    }
});

it('denies every ability on these models to a non-admin', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $role = Role::factory()->create();

    expect($user->can('view', $role))->toBeFalse()
        ->and($user->can('update', $role))->toBeFalse()
        ->and($user->can('view', $user))->toBeFalse();
});

it('allows every ability on these models to an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $role = Role::factory()->create();

    expect($admin->can('view', $role))->toBeTrue()
        ->and($admin->can('update', $role))->toBeTrue()
        ->and($admin->can('delete', $role))->toBeTrue();
});
