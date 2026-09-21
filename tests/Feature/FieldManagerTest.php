<?php

use App\Filament\Pages\FieldManager;
use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Models\User;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

// S-3.1: the Field Manager is a thin UI over SchemaManager -- every assertion
// here is about the page wiring (module scoping, ACL gate, form -> request
// mapping), not schema-change correctness, which SchemaManagerTest already
// owns exhaustively.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
});

function fieldManagerModule(): Module
{
    return Module::factory()->create(['key' => 'fm_test', 'table_name' => 'fm_test', 'is_custom' => false, 'is_system' => false]);
}

it('denies the page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(FieldManager::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    fieldManagerModule();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(FieldManager::getUrl())->assertSuccessful();
});

it('lists only the fields belonging to the selected module', function () {
    $module = fieldManagerModule();
    $other = Module::factory()->create(['key' => 'fm_other', 'table_name' => 'fm_other', 'is_custom' => false, 'is_system' => false]);
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'own_field', 'text')), actorId: null);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $other->key, 'other_field', 'text')), actorId: null);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->assertCanSeeTableRecords(Field::query()->where('module_id', $module->id)->get())
        ->assertCanNotSeeTableRecords(Field::query()->where('module_id', $other->id)->get());
});

it('adds a field through the create action, creating a real column', function () {
    $module = fieldManagerModule();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->callTableAction('create', data: [
            'name' => 'nickname',
            'label' => 'Nickname',
            'type' => 'text',
            'length' => 100,
        ])
        ->assertHasNoTableActionErrors();

    expect(Schema::hasColumn('fm_test_custom', 'nickname'))->toBeTrue()
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'nickname')->exists())->toBeTrue();
});

it('edits a field through the edit action', function () {
    $module = fieldManagerModule();
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'about', 'text')), actorId: null);
    $field = Field::query()->where('module_id', $module->id)->where('name', 'about')->firstOrFail();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->callTableAction('edit', $field, data: [
            'name' => 'about',
            'label' => 'About the lead',
            'type' => 'text',
            'reportable' => true,
            'importable' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($field->fresh()->label)->toBe('About the lead');
});

it('deletes an unreferenced field through the delete action', function () {
    $module = fieldManagerModule();
    $manager = app(SchemaManager::class);
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'temp', 'text')), actorId: null);
    $field = Field::query()->where('module_id', $module->id)->where('name', 'temp')->firstOrFail();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->callTableAction('delete', $field)
        ->assertHasNoTableActionErrors();

    expect(Field::query()->where('id', $field->id)->exists())->toBeFalse()
        ->and(Field::withTrashed()->where('id', $field->id)->exists())->toBeTrue()
        ->and(Schema::hasColumn('fm_test_custom', 'temp'))->toBeTrue();
});

it('hides the edit and delete actions for a system field', function () {
    $module = fieldManagerModule();
    Field::factory()->create(['module_id' => $module->id, 'name' => 'core_status', 'is_system' => true]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $field = Field::query()->where('module_id', $module->id)->where('name', 'core_status')->firstOrFail();

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->assertTableActionHidden('edit', $field)
        ->assertTableActionHidden('delete', $field);
});

it('hides the edit and delete actions for a base field that is not_custom, since it lives outside the sidecar', function () {
    // A base Contactable column (e.g. 'source' on leads) is registered as Field
    // metadata with is_custom=false, is_system=false -- SchemaManager's modify/delete
    // only ever targets the {table}_custom sidecar, so editing one through here would
    // fail with "column not found" against the sidecar. Regression test for that.
    $module = fieldManagerModule();
    Field::factory()->create(['module_id' => $module->id, 'name' => 'base_field', 'is_custom' => false, 'is_system' => false]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $field = Field::query()->where('module_id', $module->id)->where('name', 'base_field')->firstOrFail();

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->assertTableActionHidden('edit', $field)
        ->assertTableActionHidden('delete', $field);
});
