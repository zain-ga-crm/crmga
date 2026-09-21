<?php

use App\Filament\Pages\ChangeHistory;
use App\Models\Metadata\Change;
use App\Models\Metadata\Module;
use App\Models\User;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

// S-3.4: the Change History page is a thin, read-mostly UI over the `changes` table
// ChangeDescriber and SchemaManager already own the real logic (description text and
// rollback execution, respectively) -- these tests are about the page's own wiring.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
});

// Each test uses its own module key/table -- the sidecar SchemaManager creates via raw
// DDL isn't dropped by DatabaseTruncation between tests (it only truncates rows in
// migrated tables), so reusing one key/field-name pair across tests in this file would
// collide with a leftover column from an earlier test.
function changeHistoryModule(string $suffix): Module
{
    $key = 'ch_test_'.$suffix;

    return Module::factory()->create(['key' => $key, 'table_name' => $key, 'is_custom' => false]);
}

it('denies the page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(ChangeHistory::getUrl())->assertForbidden();
});

it('allows the page to an admin user and lists changes with a plain-language description', function () {
    $module = changeHistoryModule('list');
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'nickname', 'text')), actorId: null);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(ChangeHistory::getUrl())
        ->assertSuccessful()
        ->assertSee('Added field [nickname] (text) to ['.$module->key.']');
});

it('shows the rollback action for an applied field.add change', function () {
    $module = changeHistoryModule('visible');
    $manager = app(SchemaManager::class);
    // The first add creates the sidecar table -- no snapshot is possible for it
    // (nothing to protect yet). The second add runs against an already-existing
    // table, so it does get snapshotted, and is the one these tests target.
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'sibling', 'text')), actorId: null);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'nickname', 'text')), actorId: null);
    $change = Change::query()->where('kind', 'field.add')->where('target_module', $module->key)->where('target_field', 'nickname')->firstOrFail();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ChangeHistory::class)->assertTableActionVisible('rollback', $change);
});

it('hides the rollback action for a change that has already been rolled back', function () {
    $module = changeHistoryModule('rolledback');
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'sibling', 'text')), actorId: null);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'nickname', 'text')), actorId: null);
    $change = Change::query()->where('kind', 'field.add')->where('target_module', $module->key)->where('target_field', 'nickname')->firstOrFail();
    $change->update(['status' => 'rolled_back']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ChangeHistory::class)->assertTableActionHidden('rollback', $change->fresh());
});

it('hides the rollback action for a non-field kind, such as an option list change', function () {
    $change = Change::factory()->create(['kind' => 'optionlist.created', 'target_module' => 'lead_temperature']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ChangeHistory::class)->assertTableActionHidden('rollback', $change);
});

it('rolls back a field.add change through the rollback action', function () {
    $module = changeHistoryModule('rollback');
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'sibling', 'text')), actorId: null);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'nickname', 'text')), actorId: null);
    $change = Change::query()->where('kind', 'field.add')->where('target_module', $module->key)->where('target_field', 'nickname')->firstOrFail();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(ChangeHistory::class)->callTableAction('rollback', $change);

    expect($change->fresh()->status)->toBe('rolled_back')
        ->and(Schema::hasColumn($module->table_name.'_custom', 'nickname'))->toBeFalse();
});
