<?php

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\User;
use App\Support\MetadataValidationException;
use App\Support\OptionListManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

it('creates a new option list', function () {
    $manager = app(OptionListManager::class);

    $list = $manager->createList('lead_temperature', 'Lead temperature');

    expect($list)->toBeInstanceOf(OptionList::class)
        ->and($list->is_system)->toBeFalse()
        ->and(OptionList::query()->where('key', 'lead_temperature')->exists())->toBeTrue();
});

it('rejects creating a list with a key that already exists', function () {
    OptionList::factory()->create(['key' => 'lead_temperature']);
    $manager = app(OptionListManager::class);

    expect(fn () => $manager->createList('lead_temperature', 'Lead temperature'))
        ->toThrow(MetadataValidationException::class);
});

it('rejects creating a list with a key that does not match the pattern', function () {
    $manager = app(OptionListManager::class);

    expect(fn () => $manager->createList('Lead Temperature', 'Lead temperature'))
        ->toThrow(MetadataValidationException::class);
});

it('renames a list\'s label', function () {
    $list = OptionList::factory()->create(['label' => 'Old label']);
    $manager = app(OptionListManager::class);

    $manager->renameList($list->key, 'New label');

    expect($list->fresh()->label)->toBe('New label');
});

it('rejects renaming a system-locked list', function () {
    $list = OptionList::factory()->create(['is_system' => true]);
    $manager = app(OptionListManager::class);

    expect(fn () => $manager->renameList($list->key, 'New label'))
        ->toThrow(MetadataValidationException::class);
});

it('updates an item\'s label and color without needing confirm_lossy', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold', 'label' => 'Gold', 'color' => null]);
    $manager = app(OptionListManager::class);

    $item = $manager->updateItem($list->key, 'gold', newLabel: 'Gold tier', newColor: 'warning');

    expect($item->value)->toBe('gold')
        ->and($item->label)->toBe('Gold tier')
        ->and($item->color)->toBe('warning');
});

it('renames an unused item\'s value freely', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);
    $manager = app(OptionListManager::class);

    $manager->updateItem($list->key, 'gold', newValue: 'platinum');

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'platinum')->exists())->toBeTrue()
        ->and(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->exists())->toBeFalse();
});

it('requires confirm_lossy to rename a value that is in use, then migrates stored rows', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $module = optionListModuleWithColumn('tier');
    Field::factory()->create(['module_id' => $module->id, 'name' => 'tier', 'type' => 'enum', 'option_list_id' => $list->id]);
    $rowId = (string) Str::uuid();
    DB::table($module->table_name)->insert(['id' => $rowId, 'tier' => 'gold']);

    $manager = app(OptionListManager::class);

    expect(fn () => $manager->updateItem($list->key, 'gold', newValue: 'platinum'))
        ->toThrow(MetadataValidationException::class);

    $manager->updateItem($list->key, 'gold', newValue: 'platinum', confirmLossy: true);

    expect(DB::table($module->table_name)->where('id', $rowId)->value('tier'))->toBe('platinum');
});

it('migrates a renamed value inside a multienum json column', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $table = 'ol_test_'.Str::random(8);
    Schema::create($table, function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->json('tiers')->nullable();
        $t->timestamps();
    });
    $module = Module::factory()->create(['key' => $table, 'table_name' => $table, 'is_custom' => true]);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'tiers', 'type' => 'multienum', 'option_list_id' => $list->id]);
    $rowId = (string) Str::uuid();
    DB::table($table)->insert(['id' => $rowId, 'tiers' => json_encode(['gold', 'silver'])]);

    $manager = app(OptionListManager::class);
    $manager->updateItem($list->key, 'gold', newValue: 'platinum', confirmLossy: true);

    expect(json_decode((string) DB::table($table)->where('id', $rowId)->value('tiers'), true))->toBe(['platinum', 'silver']);
});

it('adds an option item to a list', function () {
    $list = OptionList::factory()->create();
    $manager = app(OptionListManager::class);

    $item = $manager->addItem($list->key, 'gold', 'Gold');

    expect($item)->toBeInstanceOf(OptionItem::class)
        ->and(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->exists())->toBeTrue();
});

it('rejects adding a duplicate value', function () {
    $list = OptionList::factory()->create();
    $manager = app(OptionListManager::class);

    $manager->addItem($list->key, 'gold', 'Gold');

    expect(fn () => $manager->addItem($list->key, 'gold', 'Gold again'))
        ->toThrow(MetadataValidationException::class);
});

it('rejects any item change on a system-locked list', function () {
    $list = OptionList::factory()->create(['is_system' => true]);
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);
    $manager = app(OptionListManager::class);

    expect(fn () => $manager->addItem($list->key, 'silver', 'Silver'))
        ->toThrow(MetadataValidationException::class)
        ->and(fn () => $manager->removeItem($list->key, 'gold'))
        ->toThrow(MetadataValidationException::class)
        ->and(fn () => $manager->reorderItems($list->key, ['gold']))
        ->toThrow(MetadataValidationException::class);
});

it('removes an unused option freely', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);
    $manager = app(OptionListManager::class);

    $manager->removeItem($list->key, 'gold');

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->exists())->toBeFalse();
});

function optionListModuleWithColumn(string $column): Module
{
    $table = 'ol_test_'.Str::random(8);

    Schema::create($table, function (Blueprint $t) use ($column): void {
        $t->uuid('id')->primary();
        $t->string($column)->nullable();
        $t->timestamps();
    });

    return Module::factory()->create(['key' => $table, 'table_name' => $table, 'is_custom' => true]);
}

it('requires confirm_lossy to remove an option that is in use', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $module = optionListModuleWithColumn('tier');
    Field::factory()->create(['module_id' => $module->id, 'name' => 'tier', 'type' => 'enum', 'option_list_id' => $list->id]);
    DB::table($module->table_name)->insert(['id' => (string) Str::uuid(), 'tier' => 'gold']);

    $manager = app(OptionListManager::class);

    expect(fn () => $manager->removeItem($list->key, 'gold'))
        ->toThrow(MetadataValidationException::class);
});

it('removes an in-use option and snapshots when confirm_lossy is given', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $module = optionListModuleWithColumn('tier');
    Field::factory()->create(['module_id' => $module->id, 'name' => 'tier', 'type' => 'enum', 'option_list_id' => $list->id]);
    DB::table($module->table_name)->insert(['id' => (string) Str::uuid(), 'tier' => 'gold']);

    $manager = app(OptionListManager::class);
    $manager->removeItem($list->key, 'gold', confirmLossy: true);

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->exists())->toBeFalse();
});

it('detects an in-use value inside a multienum json column', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $table = 'ol_test_'.Str::random(8);
    Schema::create($table, function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->json('tiers')->nullable();
        $t->timestamps();
    });
    $module = Module::factory()->create(['key' => $table, 'table_name' => $table, 'is_custom' => true]);
    Field::factory()->create(['module_id' => $module->id, 'name' => 'tiers', 'type' => 'multienum', 'option_list_id' => $list->id]);
    DB::table($table)->insert(['id' => (string) Str::uuid(), 'tiers' => json_encode(['gold', 'silver'])]);

    $manager = app(OptionListManager::class);

    expect(fn () => $manager->removeItem($list->key, 'gold'))
        ->toThrow(MetadataValidationException::class);
});

it('reorders items', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold', 'sort_order' => 0]);
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'silver', 'sort_order' => 1]);
    $manager = app(OptionListManager::class);

    $manager->reorderItems($list->key, ['silver', 'gold']);

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'silver')->first()->sort_order)->toBe(0)
        ->and(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->first()->sort_order)->toBe(1);
});

it('rejects reordering with a value set that does not match', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'silver']);
    $manager = app(OptionListManager::class);

    expect(fn () => $manager->reorderItems($list->key, ['gold']))
        ->toThrow(MetadataValidationException::class);
});

it('records the actor and before/after state on the change log for add, remove and reorder', function () {
    $list = OptionList::factory()->create();
    $actor = User::factory()->create();
    $manager = app(OptionListManager::class);

    $manager->addItem($list->key, 'gold', 'Gold', actorId: $actor->id);
    $addChange = Change::query()->where('kind', 'option.added')->latest()->first();

    expect($addChange->actor_id)->toBe($actor->id)
        ->and($addChange->payload['before'])->toBeNull()
        ->and($addChange->payload['after']['value'])->toBe('gold');

    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'silver', 'sort_order' => 1]);
    $manager->reorderItems($list->key, ['silver', 'gold'], actorId: $actor->id);
    $reorderChange = Change::query()->where('kind', 'option.reordered')->latest()->first();

    expect($reorderChange->actor_id)->toBe($actor->id)
        ->and($reorderChange->payload['before'])->toBe(['gold', 'silver'])
        ->and($reorderChange->payload['after'])->toBe(['silver', 'gold']);

    $manager->removeItem($list->key, 'gold', actorId: $actor->id);
    $removeChange = Change::query()->where('kind', 'option.removed')->latest()->first();

    expect($removeChange->actor_id)->toBe($actor->id)
        ->and($removeChange->payload['after'])->toBeNull()
        ->and($removeChange->payload['before']['value'])->toBe('gold');
});
