<?php

use App\Filament\Pages\DropdownEditor;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-3.2: the Dropdown Editor is a thin UI over OptionListManager -- every assertion
// here is about the page wiring (list scoping, ACL gate, form -> manager-call
// mapping), not option-list correctness, which OptionListManagerTest already owns.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
});

it('denies the page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(DropdownEditor::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    OptionList::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(DropdownEditor::getUrl())->assertSuccessful();
});

it('lists only the items belonging to the selected list', function () {
    $list = OptionList::factory()->create();
    $other = OptionList::factory()->create();
    OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'own']);
    OptionItem::factory()->create(['option_list_id' => $other->id, 'value' => 'other']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->assertCanSeeTableRecords(OptionItem::query()->where('option_list_id', $list->id)->get())
        ->assertCanNotSeeTableRecords(OptionItem::query()->where('option_list_id', $other->id)->get());
});

it('creates a new list through the create-list action', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->callTableAction('createList', data: ['key' => 'lead_temperature', 'label' => 'Lead temperature'])
        ->assertHasNoTableActionErrors();

    expect(OptionList::query()->where('key', 'lead_temperature')->exists())->toBeTrue();
});

it('adds an item through the create action', function () {
    $list = OptionList::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->callTableAction('create', data: ['value' => 'gold', 'label' => 'Gold', 'color' => 'warning', 'is_active' => true])
        ->assertHasNoTableActionErrors();

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'gold')->exists())->toBeTrue();
});

it('edits an item through the edit action', function () {
    $list = OptionList::factory()->create();
    $item = OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold', 'label' => 'Gold']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->callTableAction('edit', $item, data: ['value' => 'gold', 'label' => 'Gold tier', 'is_active' => true])
        ->assertHasNoTableActionErrors();

    expect($item->fresh()->label)->toBe('Gold tier');
});

it('deletes an unreferenced item through the delete action', function () {
    $list = OptionList::factory()->create();
    $item = OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->callTableAction('delete', $item)
        ->assertHasNoTableActionErrors();

    expect(OptionItem::query()->where('id', $item->id)->exists())->toBeFalse();
});

it('hides the add/edit/delete/rename actions for a system-locked list', function () {
    $list = OptionList::factory()->create(['is_system' => true]);
    $item = OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold']);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('renameList')
        ->assertTableActionHidden('edit', $item)
        ->assertTableActionHidden('delete', $item);
});

it('reorders items via reorderTable, routed through OptionListManager', function () {
    $list = OptionList::factory()->create();
    $gold = OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'gold', 'sort_order' => 0]);
    $silver = OptionItem::factory()->create(['option_list_id' => $list->id, 'value' => 'silver', 'sort_order' => 1]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->call('reorderTable', [$silver->id, $gold->id]);

    expect($silver->fresh()->sort_order)->toBe(0)
        ->and($gold->fresh()->sort_order)->toBe(1);
});
