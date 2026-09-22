<?php

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

// S-4.6 (user management half): user CRUD plus role assignment. Access
// itself is entirely Acl::effective() reading whatever roles are assigned
// here -- this file is only about the resource wiring: the admin gate, the
// self-delete guard, and that role assignment actually persists.
uses(DatabaseTruncation::class);

beforeEach(fn () => promotePrimaryTenant());

it('denies the resource to a non-admin user', function () {
    $this->actingAs(User::factory()->create());

    $this->get(UserResource::getUrl('index'))->assertForbidden();
});

it('allows the resource to an admin user', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(UserResource::getUrl('index'))->assertSuccessful();
});

it('creates a user with a hashed password and assigned roles', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $role = Role::factory()->create();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Agent',
            'email' => 'new.agent@example.test',
            'password' => 'a-strong-password',
            'status' => 'active',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new.agent@example.test')->firstOrFail();
    expect(Hash::check('a-strong-password', $user->password))->toBeTrue()
        ->and($user->roles->pluck('id'))->toContain($role->id);
});

it('leaves the existing password unchanged when the field is left blank on edit', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $user = User::factory()->create(['password' => 'original-password']);
    $originalHash = $user->password;

    Livewire::test(UserResource\Pages\EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->password)->toBe($originalHash);
});

it('hides the delete action for the currently signed-in user but not for another user', function () {
    $me = User::factory()->create(['is_admin' => true]);
    $someoneElse = User::factory()->create();
    $this->actingAs($me);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $me)
        ->assertTableActionVisible('delete', $someoneElse);
});

it('excludes the signed-in user from a bulk delete but still deletes the rest', function () {
    $me = User::factory()->create(['is_admin' => true]);
    $someoneElse = User::factory()->create();
    $this->actingAs($me);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('delete', [$me->id, $someoneElse->id]);

    expect(User::query()->find($me->id))->not->toBeNull()
        ->and(User::query()->find($someoneElse->id))->toBeNull();
});
