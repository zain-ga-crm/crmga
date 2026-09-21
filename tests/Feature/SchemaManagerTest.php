<?php

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\RoleFieldPermission;
use App\Models\User;
use App\Support\SchemaManager\ChangeResult;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use App\Support\SchemaManager\SchemaValidationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

function leadsModule(): Module
{
    return Module::factory()->create(['key' => 'leads_sm_test', 'table_name' => 'leads_sm_test', 'is_custom' => false]);
}

it('adds a field and creates a real column via the sidecar', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $plan = $manager->plan(new FieldChangeRequest('add', $module->key, 'nickname', 'text', ['length' => 100]));
    $result = $manager->apply($plan, actorId: null);

    expect($result)->toBeInstanceOf(ChangeResult::class)
        ->and($result->success)->toBeTrue()
        ->and(Schema::hasTable('leads_sm_test_custom'))->toBeTrue()
        ->and(Schema::hasColumn('leads_sm_test_custom', 'nickname'))->toBeTrue()
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'nickname')->exists())->toBeTrue();
});

it('rejects a reserved field name', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'password', 'text')))
        ->toThrow(SchemaValidationException::class);
});

it('rejects a name that does not match the pattern', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    foreach (['Nickname', '1field', 'bad-name', 'a'] as $bad) {
        expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, $bad, 'text')))
            ->toThrow(SchemaValidationException::class);
    }
});

it('unconditionally rejects a field named tenant_id', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'tenant_id', 'text')))
        ->toThrow(SchemaValidationException::class);
});

it('rejects changes to a system-locked module', function () {
    $module = Module::factory()->create(['key' => 'locked', 'table_name' => 'locked', 'is_system' => true]);
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'note', 'text')))
        ->toThrow(SchemaValidationException::class);
});

it('rejects changes to a system field', function () {
    $module = leadsModule();
    $field = Field::factory()->create(['module_id' => $module->id, 'name' => 'core_status', 'is_system' => true]);
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('delete', $module->key, $field->name)))
        ->toThrow(SchemaValidationException::class);
});

it('rejects an unknown field type', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'weird', 'sql_injection_type')))
        ->toThrow(SchemaValidationException::class);
});

it('rejects an enum field without an option_list_id', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'category', 'enum')))
        ->toThrow(SchemaValidationException::class);
});

it('rejects a duplicate field name including soft-deleted ones', function () {
    $module = leadsModule();
    Field::factory()->create(['module_id' => $module->id, 'name' => 'dup']);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'gone'])->delete();
    $manager = app(SchemaManager::class);

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'dup', 'text')))
        ->toThrow(SchemaValidationException::class)
        ->and(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'gone', 'text')))
        ->toThrow(SchemaValidationException::class);
});

it('sanitises an injection attempt through length into a plain integer', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $plan = $manager->plan(new FieldChangeRequest(
        'add', $module->key, 'evil_len', 'text', ['length' => '255); DROP TABLE users; --'],
    ));

    expect(implode(' ', $plan->ddl))->toContain('varchar(255)')
        ->not->toContain('DROP TABLE');
});

it('never embeds an injected default value into the ddl', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $plan = $manager->plan(new FieldChangeRequest(
        'add', $module->key, 'evil_default', 'text', ['default' => "'); DROP TABLE users; --"],
    ));

    expect(implode(' ', $plan->ddl))->not->toContain('DROP TABLE');
});

it('blocks a type change the contract does not classify, rather than allowing it silently', function () {
    $module = leadsModule();
    $field = Field::factory()->create(['module_id' => $module->id, 'name' => 'contact_email', 'type' => 'email']);
    $manager = app(SchemaManager::class);

    // The contract's type_change_matrix only lists a handful of explicit pairs
    // (text<->textarea, int<->decimal, date<->datetime, enum->text). email->phone
    // is not one of them -- it must fail closed (audit finding: it used to fall
    // through classifyModify() as 'unknown' and be treated as freely allowed).
    expect(fn () => $manager->plan(new FieldChangeRequest('modify', $module->key, $field->name, 'phone')))
        ->toThrow(SchemaValidationException::class);
});

it('applies a default via a bound follow-up UPDATE, backfilling existing NULL rows on add', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    // First field creates the sidecar; insert a row so there's something to backfill.
    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'first_field', 'text')), actorId: null);
    $rowId = (string) Str::uuid();
    DB::table('leads_sm_test_custom')->insert(['id' => $rowId, 'first_field' => 'x']);

    $plan = $manager->plan(new FieldChangeRequest(
        'add', $module->key, 'welcome_message', 'text', ['default' => 'Hello'],
    ));
    $result = $manager->apply($plan, actorId: null);

    expect($result->success)->toBeTrue()
        ->and(DB::table('leads_sm_test_custom')->where('id', $rowId)->value('welcome_message'))->toBe('Hello');
});

it('backfills a default on modify only for rows that are still NULL', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply($manager->plan(new FieldChangeRequest('add', $module->key, 'note', 'text')), actorId: null);
    $filledId = (string) Str::uuid();
    $nullId = (string) Str::uuid();
    DB::table('leads_sm_test_custom')->insert([
        ['id' => $filledId, 'note' => 'already set'],
        ['id' => $nullId, 'note' => null],
    ]);

    $plan = $manager->plan(new FieldChangeRequest('modify', $module->key, 'note', 'text', ['default' => 'Untitled']));
    $manager->apply($plan, actorId: null);

    expect(DB::table('leads_sm_test_custom')->where('id', $filledId)->value('note'))->toBe('already set')
        ->and(DB::table('leads_sm_test_custom')->where('id', $nullId)->value('note'))->toBe('Untitled');
});

it('soft-deletes the metadata row on delete and keeps the column', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $addPlan = $manager->plan(new FieldChangeRequest('add', $module->key, 'temp_field', 'text'));
    $manager->apply($addPlan, actorId: null);

    $deletePlan = $manager->plan(new FieldChangeRequest('delete', $module->key, 'temp_field'));
    $result = $manager->apply($deletePlan, actorId: null);

    expect($result->success)->toBeTrue()
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'temp_field')->exists())->toBeFalse()
        ->and(Field::withTrashed()->where('module_id', $module->id)->where('name', 'temp_field')->exists())->toBeTrue()
        ->and(Schema::hasColumn('leads_sm_test_custom', 'temp_field'))->toBeTrue();
});

it('blocks a delete when a layout references the field, until confirm_lossy', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'referenced_field', 'text')),
        actorId: null,
    );

    Layout::factory()->create([
        'module_id' => $module->id,
        'view' => 'detail',
        'definition' => [
            'version' => 1,
            'view' => 'detail',
            'module' => $module->key,
            'content' => ['panels' => [[
                'key' => 'main',
                'label' => 'Main',
                'rows' => [[['field' => 'referenced_field']]],
            ]]],
        ],
    ]);

    expect(fn () => $manager->plan(new FieldChangeRequest('delete', $module->key, 'referenced_field')))
        ->toThrow(SchemaValidationException::class);

    $plan = $manager->plan(new FieldChangeRequest('delete', $module->key, 'referenced_field', confirmLossy: true));
    expect($manager->apply($plan, actorId: null)->success)->toBeTrue();
});

it('blocks a delete when a role narrows the field, until confirm_lossy', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'role_narrowed_field', 'text')),
        actorId: null,
    );

    $role = Role::factory()->create(['name' => 'Sales Rep']);
    RoleFieldPermission::factory()->create([
        'role_id' => $role->id,
        'module_key' => $module->key,
        'field_name' => 'role_narrowed_field',
    ]);

    expect(fn () => $manager->plan(new FieldChangeRequest('delete', $module->key, 'role_narrowed_field')))
        ->toThrow(SchemaValidationException::class);

    $plan = $manager->plan(new FieldChangeRequest('delete', $module->key, 'role_narrowed_field', confirmLossy: true));
    expect($manager->apply($plan, actorId: null)->success)->toBeTrue();
});

it('reports which layouts and roles reference a field via impact()', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'watched_field', 'text')),
        actorId: null,
    );

    Layout::factory()->create([
        'module_id' => $module->id,
        'view' => 'list',
        'definition' => [
            'version' => 1,
            'view' => 'list',
            'module' => $module->key,
            'content' => ['columns' => [['field' => 'watched_field', 'priority' => 1]]],
        ],
    ]);
    $role = Role::factory()->create(['name' => 'Sales Rep']);
    RoleFieldPermission::factory()->create([
        'role_id' => $role->id,
        'module_key' => $module->key,
        'field_name' => 'watched_field',
    ]);

    $impact = $manager->impact($module->key, 'watched_field');

    expect($impact['layouts'])->toHaveCount(1)
        ->and($impact['layouts'][0]['view'])->toBe('list')
        ->and($impact['roles'])->toBe(['Sales Rep'])
        ->and($impact['integrations'])->toBe([]);
});

it('does not require confirm_lossy to delete an unreferenced field', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'unreferenced_field', 'text')),
        actorId: null,
    );

    $plan = $manager->plan(new FieldChangeRequest('delete', $module->key, 'unreferenced_field'));
    expect($manager->apply($plan, actorId: null)->success)->toBeTrue();
});

it('rolls back a delete without a snapshot, un-soft-deleting the field metadata', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);
    $actor = User::factory()->create();

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zrollback_delete', 'text')),
        actorId: null,
    );
    $deleteResult = $manager->apply(
        $manager->plan(new FieldChangeRequest('delete', $module->key, 'zrollback_delete')),
        actorId: null,
    );

    expect($deleteResult->snapshotPath)->toBeNull();

    $rollbackResult = $manager->rollback($deleteResult->changeId, actorId: $actor->id);

    expect($rollbackResult->success)->toBeTrue()
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'zrollback_delete')->exists())->toBeTrue()
        ->and(Schema::hasColumn('leads_sm_test_custom', 'zrollback_delete'))->toBeTrue()
        ->and(Change::query()->findOrFail($deleteResult->changeId)->status)->toBe('rolled_back');
});

it('createSidecar is idempotent', function () {
    $manager = app(SchemaManager::class);

    $manager->createSidecar('some_table');
    $manager->createSidecar('some_table'); // no error on repeat

    expect(Schema::hasTable('some_table_custom'))->toBeTrue();
});

it('widens a text field length safely without requiring confirmation', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zwiden_text', 'text', ['length' => 100])),
        actorId: null,
    );

    $plan = $manager->plan(new FieldChangeRequest('modify', $module->key, 'zwiden_text', 'text', ['length' => 300]));
    $result = $manager->apply($plan, actorId: null);

    expect($result->success)->toBeTrue()
        ->and(implode(' ', $plan->ddl))->toContain('MODIFY COLUMN')->toContain('varchar(300)')
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'zwiden_text')->first()->max_length)->toBe(300);
});

it('requires confirm_lossy to narrow a text field length', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zshrink_gate', 'text', ['length' => 300])),
        actorId: null,
    );

    expect(fn () => $manager->plan(new FieldChangeRequest('modify', $module->key, 'zshrink_gate', 'text', ['length' => 50])))
        ->toThrow(SchemaValidationException::class);
});

it('narrows a text field length and snapshots when confirm_lossy is given', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zshrink_ok', 'text', ['length' => 300])),
        actorId: null,
    );

    $plan = $manager->plan(new FieldChangeRequest(
        'modify', $module->key, 'zshrink_ok', 'text', ['length' => 50], confirmLossy: true,
    ));
    $result = $manager->apply($plan, actorId: null);

    expect($result->success)->toBeTrue()
        ->and($result->snapshotPath)->not->toBeNull()
        ->and(Field::query()->where('module_id', $module->id)->where('name', 'zshrink_ok')->first()->max_length)->toBe(50);
});

it("blocks changing a relate field's related module while data exists", function () {
    $module = leadsModule();
    $relatedA = Module::factory()->create(['key' => 'related_a', 'table_name' => 'related_a']);
    $relatedB = Module::factory()->create(['key' => 'related_b', 'table_name' => 'related_b']);
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zlinked_blocked', 'relate', [
            'related_module_id' => $relatedA->id,
            'related_display_field' => 'name',
        ])),
        actorId: null,
    );

    DB::table('leads_sm_test_custom')->insert([
        'id' => (string) Str::uuid(),
        'zlinked_blocked' => (string) Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $manager->plan(new FieldChangeRequest('modify', $module->key, 'zlinked_blocked', 'relate', [
        'related_module_id' => $relatedB->id,
        'related_display_field' => 'name',
    ])))->toThrow(SchemaValidationException::class);
});

it('adds a real index for an indexable field type on add', function () {
    $module = leadsModule();
    $related = Module::factory()->create(['key' => 'related_c', 'table_name' => 'related_c']);
    $manager = app(SchemaManager::class);

    $plan = $manager->plan(new FieldChangeRequest('add', $module->key, 'zlinked_indexed', 'relate', [
        'related_module_id' => $related->id,
        'related_display_field' => 'name',
    ]));
    $manager->apply($plan, actorId: null);

    $hasIndexOnLinked = collect(Schema::getIndexes('leads_sm_test_custom'))
        ->contains(fn (array $index): bool => in_array('zlinked_indexed', $index['columns'], true));

    expect(implode(' ', $plan->ddl))->toContain('ADD INDEX')
        ->and($hasIndexOnLinked)->toBeTrue();
});

it('records before/after on the change log for add and modify', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $addPlan = $manager->plan(new FieldChangeRequest('add', $module->key, 'zchangelog', 'text', ['length' => 100]));
    $addResult = $manager->apply($addPlan, actorId: null);
    $addChange = Change::query()->findOrFail($addResult->changeId);

    expect($addChange->payload['before'])->toBeNull()
        ->and($addChange->payload['after']['max_length'])->toBe(100);

    $modifyPlan = $manager->plan(new FieldChangeRequest('modify', $module->key, 'zchangelog', 'text', ['length' => 200]));
    $modifyResult = $manager->apply($modifyPlan, actorId: null);
    $modifyChange = Change::query()->findOrFail($modifyResult->changeId);

    expect($modifyChange->payload['before']['max_length'])->toBe(100)
        ->and($modifyChange->payload['after']['max_length'])->toBe(200);
});

it('derives a label from the field name when none is given, and accepts an explicit one', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $auto = $manager->plan(new FieldChangeRequest('add', $module->key, 'call_status', 'text'));
    $manager->apply($auto, actorId: null);
    expect(Field::query()->where('module_id', $module->id)->where('name', 'call_status')->value('label'))
        ->toBe('Call status');

    $explicit = $manager->plan(new FieldChangeRequest('add', $module->key, 'crs_score', 'int', ['label' => 'CRS score']));
    $manager->apply($explicit, actorId: null);
    expect(Field::query()->where('module_id', $module->id)->where('name', 'crs_score')->value('label'))
        ->toBe('CRS score');

    $modify = $manager->plan(new FieldChangeRequest('modify', $module->key, 'call_status', 'text', ['label' => 'Call outcome']));
    $manager->apply($modify, actorId: null);
    expect(Field::query()->where('module_id', $module->id)->where('name', 'call_status')->value('label'))
        ->toBe('Call outcome');
});

it('rollback of an add removes only that field, leaving the sidecar table and sibling columns intact', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);
    $actor = User::factory()->create();

    // First add creates the sidecar table — no snapshot is possible for it (nothing to
    // protect yet). The second add runs against an already-existing table, so it does
    // get snapshotted, and is the one this test rolls back.
    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zrollback_sibling', 'text', ['length' => 100])),
        actorId: null,
    );
    $plan = $manager->plan(new FieldChangeRequest('add', $module->key, 'zrollback_add', 'text', ['length' => 100]));
    $result = $manager->apply($plan, actorId: null);

    $manager->rollback($result->changeId, actorId: $actor->id);

    expect(Field::withTrashed()->where('module_id', $module->id)->where('name', 'zrollback_add')->exists())->toBeFalse()
        ->and(Schema::hasColumn('leads_sm_test_custom', 'zrollback_add'))->toBeFalse()
        ->and(Schema::hasColumn('leads_sm_test_custom', 'zrollback_sibling'))->toBeTrue()
        ->and(Change::query()->findOrFail($result->changeId)->status)->toBe('rolled_back');
});

it('rollback of a modify restores the previous field attributes, not just the column', function () {
    $module = leadsModule();
    $manager = app(SchemaManager::class);
    $actor = User::factory()->create();

    // A prior add ensures the sidecar table already exists, so the modify below gets a
    // real snapshot to roll back to.
    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zrollback_seed', 'text', ['length' => 100])),
        actorId: null,
    );
    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zrollback_modify', 'text', ['length' => 100])),
        actorId: null,
    );

    $modifyPlan = $manager->plan(new FieldChangeRequest('modify', $module->key, 'zrollback_modify', 'text', ['length' => 300]));
    $modifyResult = $manager->apply($modifyPlan, actorId: null);

    $manager->rollback($modifyResult->changeId, actorId: $actor->id);

    $field = Field::query()->where('module_id', $module->id)->where('name', 'zrollback_modify')->first();

    expect($field)->not->toBeNull()
        ->and($field->max_length)->toBe(100)
        ->and(Schema::getColumnType('leads_sm_test_custom', 'zrollback_modify'))->toBe('varchar');
});

it('rejects an add once the installation-wide custom field ceiling is reached', function () {
    config(['schema-manager.max_custom_fields_total' => 1]);
    $module = leadsModule();
    $manager = app(SchemaManager::class);

    $manager->apply(
        $manager->plan(new FieldChangeRequest('add', $module->key, 'zceiling_one', 'text')),
        actorId: null,
    );

    expect(fn () => $manager->plan(new FieldChangeRequest('add', $module->key, 'zceiling_two', 'text')))
        ->toThrow(SchemaValidationException::class);
});
