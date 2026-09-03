<?php

use App\Models\Group;
use App\Models\GroupRecord;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\User;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ContactableFixture;
use Tests\Fixtures\ContactableFixturePolicy;
use Tests\Fixtures\CountVisibleFixturesJob;

uses(DatabaseTruncation::class);

beforeEach(function () {
    if (! Schema::hasTable('contactable_fixtures')) {
        Schema::create('contactable_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->contactable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
});

function roleWithLevel(string $moduleKey, string $action, AccessLevel $level): Role
{
    $role = Role::factory()->create();
    RoleModulePermission::factory()->create([
        'role_id' => $role->id,
        'module_key' => $moduleKey,
        $action => $level,
    ]);

    return $role;
}

it('lets an owner-level user see only their own records — in the query', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Owner));

    ContactableFixture::create(['first_name' => 'Mine', 'assigned_user_id' => $me->id]);
    ContactableFixture::create(['first_name' => 'Theirs', 'assigned_user_id' => $other->id]);

    $this->actingAs($me);

    expect(ContactableFixture::query()->count())->toBe(1)
        ->and(ContactableFixture::query()->first()->first_name)->toBe('Mine');
});

it('lets an owner-level user see only their own records — in a queued job', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Owner));

    ContactableFixture::create(['first_name' => 'Mine', 'assigned_user_id' => $me->id]);
    ContactableFixture::create(['first_name' => 'Theirs', 'assigned_user_id' => $other->id]);

    $this->actingAs($me);
    CountVisibleFixturesJob::$result = -1;
    CountVisibleFixturesJob::dispatchSync();

    expect(CountVisibleFixturesJob::$result)->toBe(1);
});

it('makes another user\'s owner-scoped record invisible, not found rather than forbidden', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Owner));

    $theirs = ContactableFixture::create(['first_name' => 'Theirs', 'assigned_user_id' => $other->id]);

    $this->actingAs($me);

    // The record exists, but the scope makes it unreachable — this is exactly what
    // produces a 404 (not a 403) through route-model-binding at the HTTP layer.
    expect(ContactableFixture::find($theirs->id))->toBeNull();
});

it('gives an admin unrestricted access regardless of role', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();

    ContactableFixture::create(['first_name' => 'Someone', 'assigned_user_id' => $owner->id]);

    $this->actingAs($admin);

    expect(ContactableFixture::query()->count())->toBe(1);
});

it('leaves a new module invisible to every role until explicitly granted', function () {
    Role::factory()->count(2)->create();

    $module = Module::factory()->create(['key' => 'brand_new_module']);

    foreach (Role::all() as $role) {
        $permission = RoleModulePermission::query()
            ->where('role_id', $role->id)
            ->where('module_key', $module->key)
            ->first();

        expect($permission)->not->toBeNull();
        foreach (Acl::ACTIONS as $action) {
            expect($permission->level($action))->toBe(AccessLevel::None);
        }
    }
});

it('backfills a new role with none for every existing module', function () {
    Module::factory()->create(['key' => 'existing_module']);

    $role = Role::factory()->create();

    expect(RoleModulePermission::query()->where('role_id', $role->id)->where('module_key', 'existing_module')->exists())
        ->toBeTrue();
});

it('combines two roles to the most permissive level', function () {
    $user = User::factory()->create();
    $user->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::Owner));
    $user->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::All));

    expect(app(Acl::class)->effective($user, 'contactable_fixtures', 'edit'))->toBe(AccessLevel::All);
});

it('treats an empty role set as none — deny by default', function () {
    $user = User::factory()->create();

    expect(app(Acl::class)->effective($user, 'contactable_fixtures', 'view'))->toBe(AccessLevel::None);
});

it('enforces the policy layer consistently with the query scope', function () {
    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();
    $owner->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Owner));
    $owner->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::Owner));

    $mine = ContactableFixture::create(['first_name' => 'Mine', 'assigned_user_id' => $owner->id]);
    $theirs = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'Theirs', 'assigned_user_id' => $someoneElse->id]);

    $policy = new ContactableFixturePolicy;

    expect($policy->view($owner, $mine))->toBeTrue()
        ->and($policy->view($owner, $theirs))->toBeFalse()
        ->and($policy->update($owner, $mine))->toBeTrue()
        ->and($policy->delete($owner, $mine))->toBeFalse(); // no delete grant
});

function grantRecordToGroup(Group $group, ContactableFixture $record): GroupRecord
{
    return GroupRecord::factory()->create([
        'group_id' => $group->id,
        'recordable_type' => ContactableFixture::class,
        'recordable_id' => $record->id,
    ]);
}

it('lets a group-level user see only records granted to their group — in the query', function () {
    $me = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($me);
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Group));

    $granted = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'Granted', 'assigned_user_id' => User::factory()->create()->id]);
    ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'NotGranted', 'assigned_user_id' => User::factory()->create()->id]);
    grantRecordToGroup($group, $granted);

    $this->actingAs($me);

    expect(ContactableFixture::query()->count())->toBe(1)
        ->and(ContactableFixture::query()->first()->first_name)->toBe('Granted');
});

it('makes a record outside the user\'s groups invisible', function () {
    $me = User::factory()->create();
    $myGroup = Group::factory()->create();
    $otherGroup = Group::factory()->create();
    $myGroup->users()->attach($me);
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Group));

    $theirs = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'Theirs', 'assigned_user_id' => User::factory()->create()->id]);
    grantRecordToGroup($otherGroup, $theirs);

    $this->actingAs($me);

    expect(ContactableFixture::find($theirs->id))->toBeNull();
});

it('lets a user in multiple groups see records granted to any of them', function () {
    $me = User::factory()->create();
    $groupA = Group::factory()->create();
    $groupB = Group::factory()->create();
    $me->groups()->attach([$groupA->id, $groupB->id]);
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Group));

    $viaA = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'ViaA', 'assigned_user_id' => User::factory()->create()->id]);
    $viaB = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'ViaB', 'assigned_user_id' => User::factory()->create()->id]);
    grantRecordToGroup($groupA, $viaA);
    grantRecordToGroup($groupB, $viaB);

    $this->actingAs($me);

    expect(ContactableFixture::query()->pluck('first_name')->sort()->values()->all())->toBe(['ViaA', 'ViaB']);
});

it('ranks group above owner and below all when combining roles', function () {
    $user = User::factory()->create();
    $user->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::Owner));
    $user->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::Group));

    expect(app(Acl::class)->effective($user, 'contactable_fixtures', 'edit'))->toBe(AccessLevel::Group);

    $user->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::All));
    $user->unsetRelation('roles');

    expect(app(Acl::class)->effective($user, 'contactable_fixtures', 'edit'))->toBe(AccessLevel::All);
});

it('enforces the policy layer consistently with the query scope for group level', function () {
    $me = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($me);
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'view', AccessLevel::Group));
    $me->roles()->attach(roleWithLevel('contactable_fixtures', 'edit', AccessLevel::Group));

    $granted = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'Granted', 'assigned_user_id' => User::factory()->create()->id]);
    $notGranted = ContactableFixture::withoutGlobalScopes()->create(['first_name' => 'NotGranted', 'assigned_user_id' => User::factory()->create()->id]);
    grantRecordToGroup($group, $granted);

    $policy = new ContactableFixturePolicy;

    expect($policy->view($me, $granted))->toBeTrue()
        ->and($policy->view($me, $notGranted))->toBeFalse()
        ->and($policy->update($me, $granted))->toBeTrue()
        ->and($policy->delete($me, $granted))->toBeFalse(); // no delete grant
});
