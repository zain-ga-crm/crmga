<?php

use App\Filament\Pages\LayoutEditor;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-3.3: the Layout Editor is a thin UI over LayoutManager -- every assertion here is
// about the page's own wiring (module/view scoping, form <-> definition mapping via
// LayoutRowPacker, draft/publish/revert calls), not layout-versioning correctness,
// which LayoutManagerTest already owns.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
});

function layoutEditorModule(string $suffix): Module
{
    $key = 'le_test_'.$suffix;

    return Module::factory()->create(['key' => $key, 'table_name' => $key]);
}

it('denies the page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(LayoutEditor::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    layoutEditorModule('access');
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(LayoutEditor::getUrl())->assertSuccessful();
});

it('saves a columns-view (list) definition as a new draft version', function () {
    $module = layoutEditorModule('list');
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(LayoutEditor::class)
        ->set('moduleKey', $module->key)
        ->set('viewName', 'list')
        ->set('data', [
            'columns' => [
                ['field' => 'full_name', 'label' => null, 'width' => 200, 'priority' => 1, 'sortable' => true, 'align' => 'left', 'wrap' => false, 'link' => true],
                ['field' => 'primary_email', 'label' => null, 'width' => null, 'priority' => 1, 'sortable' => true, 'align' => 'left', 'wrap' => false, 'link' => false],
            ],
            'default_sort_enabled' => true,
            'default_sort_field' => 'created_at',
            'default_sort_direction' => 'desc',
        ])
        ->call('saveDraft');

    $layout = Layout::query()->where('module_id', $module->id)->where('view', 'list')->firstOrFail();

    expect($layout->version)->toBe(1)
        ->and($layout->is_published)->toBeFalse()
        ->and($layout->definition['content']['columns'])->toHaveCount(2)
        ->and($layout->definition['content']['columns'][0]['field'])->toBe('full_name')
        ->and($layout->definition['content']['columns'][0]['link'])->toBeTrue()
        ->and($layout->definition['content']['default_sort'])->toBe(['field' => 'created_at', 'direction' => 'desc']);
});

it('saves and publishes a panels-view (detail) definition, packing flat slots into row-pairs', function () {
    $module = layoutEditorModule('detail');
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(LayoutEditor::class)
        ->set('moduleKey', $module->key)
        ->set('viewName', 'detail')
        ->set('data', [
            'tabs' => [],
            'panels' => [
                [
                    'key' => 'main',
                    'label' => 'Main',
                    'tab' => '',
                    'collapsed' => false,
                    'columns' => 2,
                    'visible_when_enabled' => false,
                    'slots' => [
                        ['field' => 'primary_email', 'label' => null, 'span' => 'half', 'readonly' => false, 'hide_when_empty' => true, 'visible_when_enabled' => false],
                        ['field' => 'phone_mobile', 'label' => null, 'span' => 'half', 'readonly' => false, 'hide_when_empty' => true, 'visible_when_enabled' => false],
                        ['field' => 'primary_address_street', 'label' => null, 'span' => 'full', 'readonly' => false, 'hide_when_empty' => true, 'visible_when_enabled' => false],
                    ],
                ],
            ],
        ])
        ->call('saveAndPublish');

    $layout = Layout::query()->where('module_id', $module->id)->where('view', 'detail')->firstOrFail();
    $rows = $layout->definition['content']['panels'][0]['rows'];

    expect($layout->is_published)->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toHaveCount(2)
        ->and($rows[0][0]['field'])->toBe('primary_email')
        ->and($rows[0][1]['field'])->toBe('phone_mobile')
        ->and($rows[1])->toHaveCount(1)
        ->and($rows[1][0]['field'])->toBe('primary_address_street')
        ->and($rows[1][0]['span'])->toBe('full');
});

it('loads an existing published layout into the form, unpacking rows back into flat slots', function () {
    $module = layoutEditorModule('reload');
    Layout::factory()->create([
        'module_id' => $module->id,
        'view' => 'detail',
        'version' => 1,
        'is_published' => true,
        'definition' => [
            'version' => 1,
            'view' => 'detail',
            'module' => $module->key,
            'content' => [
                'panels' => [[
                    'key' => 'main',
                    'label' => 'Main',
                    'order' => 0,
                    'rows' => [
                        [['field' => 'a'], ['field' => 'b']],
                        [['field' => 'c', 'span' => 'full']],
                    ],
                ]],
            ],
        ],
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $component = Livewire::test(LayoutEditor::class)
        ->set('moduleKey', $module->key)
        ->set('viewName', 'detail');

    // Filament's Repeater re-keys items by UUID once the form hydrates them (not
    // sequential ints), so pull the first panel/its slots positionally.
    $panels = array_values($component->get('data')['panels']);
    $slots = array_values($panels[0]['slots']);

    expect($slots)->toHaveCount(3)
        ->and($slots[0]['field'])->toBe('a')
        ->and($slots[1]['field'])->toBe('b')
        ->and($slots[2]['field'])->toBe('c')
        ->and($slots[2]['span'])->toBe('full');
});

it('reverts to an older version and publishes it', function () {
    $module = layoutEditorModule('revert');
    $v1 = Layout::factory()->create([
        'module_id' => $module->id,
        'view' => 'list',
        'version' => 1,
        'is_published' => false,
        'definition' => ['version' => 1, 'view' => 'list', 'module' => $module->key, 'content' => ['columns' => [['field' => 'old_field']]]],
    ]);
    Layout::factory()->create([
        'module_id' => $module->id,
        'view' => 'list',
        'version' => 2,
        'is_published' => true,
        'definition' => ['version' => 1, 'view' => 'list', 'module' => $module->key, 'content' => ['columns' => [['field' => 'new_field']]]],
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(LayoutEditor::class)
        ->set('moduleKey', $module->key)
        ->set('viewName', 'list')
        ->call('revertToVersion', $v1->id);

    $latest = Layout::query()->where('module_id', $module->id)->where('view', 'list')->orderByDesc('version')->first();

    expect($latest->version)->toBe(3)
        ->and($latest->is_published)->toBeTrue()
        ->and($latest->definition['content']['columns'][0]['field'])->toBe('old_field');
});
