<?php

use App\Filament\Pages\DropdownEditor;
use App\Filament\Pages\FieldManager;
use App\Filament\Pages\LayoutEditor;
use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\User;
use App\Support\Studio\StudioApprovalService;
use App\Support\Studio\StudioGovernance;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-8.3: Studio governance. 'disabled' blocks every Studio page outright;
// 'request_only' lets an admin stage a change but replaces the real
// manager call with a queued Change row (status 'requested') for a platform
// operator to approve or reject later (StudioApprovalService); 'self_service'
// is the pre-S-8.3 behaviour every other Studio test already covers.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
});

function studioModule(string $suffix): Module
{
    $key = 'sg_test_'.$suffix;

    return Module::factory()->create(['key' => $key, 'table_name' => $key, 'is_custom' => false, 'is_system' => false]);
}

it('defaults an untouched tenant to self_service', function () {
    $tenant = promotePrimaryTenant();

    expect(StudioGovernance::modeOf($tenant))->toBe(StudioGovernance::MODE_SELF_SERVICE);
});

it('blocks every Studio page outright when the tenant is disabled', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_DISABLED]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(FieldManager::getUrl())->assertForbidden();
    $this->get(DropdownEditor::getUrl())->assertForbidden();
    $this->get(LayoutEditor::getUrl())->assertForbidden();
});

it('queues a field change under request_only instead of applying it', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_REQUEST_ONLY]);
    $module = studioModule('field');

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    // Livewire::test() mounts the component directly rather than dispatching a
    // real request, so the DB connection itself still needs tenancy()
    // ->initialize() here regardless of StudioGovernance's own Host-header
    // fallback (see the dedicated regression test below for why that fallback
    // exists at all).
    tenancy()->initialize($tenant);

    Livewire::test(FieldManager::class)
        ->set('moduleKey', $module->key)
        ->callTableAction('create', data: [
            'name' => 'nickname',
            'label' => 'Nickname',
            'type' => 'text',
        ])
        ->assertHasNoTableActionErrors();

    tenancy()->end();

    expect(Field::query()->where('module_id', $module->id)->where('name', 'nickname')->exists())->toBeFalse();

    $change = Change::query()->where('kind', 'field.add')->where('target_field', 'nickname')->firstOrFail();
    expect($change->status)->toBe('requested')
        ->and($change->payload['module_key'])->toBe($module->key)
        ->and($change->payload['name'])->toBe('nickname');
});

it('resolves the current mode from the request host when tenancy was never initialized for this request', function () {
    // The real gap this closes: a Studio page's submit actions run inside
    // Livewire's own generic update request (POST /livewire/update), which
    // never goes through the admin panel's InitializeTenancyByDomain
    // middleware (confirmed via `artisan route:list -vv --path=livewire` --
    // that route carries only Livewire's own 'web' group, nothing
    // tenancy-related). So tenancy()->tenant is null there even mid-session.
    // Binding a Request with the same Host a real browser tab would send,
    // with no tenancy()->initialize() call at all, reproduces exactly that.
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_REQUEST_ONLY]);

    $this->app->instance('request', Request::create('http://localhost/livewire/update', 'POST'));

    expect(StudioGovernance::currentMode())->toBe(StudioGovernance::MODE_REQUEST_ONLY);
});

it('queues an option-list change under request_only instead of applying it', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_REQUEST_ONLY]);
    $list = OptionList::factory()->create();

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    tenancy()->initialize($tenant);

    Livewire::test(DropdownEditor::class)
        ->set('listKey', $list->key)
        ->callTableAction('create', data: ['value' => 'hot', 'label' => 'Hot', 'color' => null, 'is_active' => true])
        ->assertHasNoTableActionErrors();

    tenancy()->end();

    expect(OptionItem::query()->where('option_list_id', $list->id)->where('value', 'hot')->exists())->toBeFalse();

    $change = Change::query()->where('kind', 'option.requested')->firstOrFail();
    expect($change->status)->toBe('requested')
        ->and($change->payload['method'])->toBe('addItem')
        ->and($change->payload['args']['value'])->toBe('hot');
});

it('queues a layout publish under request_only, leaving the draft unpublished', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_REQUEST_ONLY]);
    $module = studioModule('layout');

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    tenancy()->initialize($tenant);

    Livewire::test(LayoutEditor::class)
        ->set('moduleKey', $module->key)
        ->set('viewName', 'list')
        ->set('data', [
            'columns' => [
                ['field' => 'created_at', 'label' => null, 'width' => null, 'priority' => 1, 'sortable' => true, 'align' => 'left', 'wrap' => false, 'link' => false],
            ],
            'default_sort_enabled' => false,
            'default_sort_field' => null,
            'default_sort_direction' => 'desc',
        ])
        ->call('saveAndPublish');

    tenancy()->end();

    $layout = Layout::query()->where('module_id', $module->id)->where('view', 'list')->firstOrFail();
    expect($layout->is_published)->toBeFalse();

    $change = Change::query()->where('kind', 'layout.requested')->firstOrFail();
    expect($change->status)->toBe('requested')
        ->and($change->payload['layout_id'])->toBe($layout->id);
});

it('replays a requested field change for real on approval', function () {
    promotePrimaryTenant();
    $module = studioModule('approve');

    $change = Change::create([
        'kind' => 'field.add',
        'target_module' => $module->key,
        'target_field' => 'nickname',
        'payload' => [
            'action' => 'add',
            'module_key' => $module->key,
            'name' => 'nickname',
            'type' => 'text',
            'options' => ['label' => 'Nickname'],
            'confirm_lossy' => false,
        ],
        'status' => 'requested',
    ]);

    app(StudioApprovalService::class)->approve($change, reviewerId: null);

    expect(Field::query()->where('module_id', $module->id)->where('name', 'nickname')->exists())->toBeTrue()
        ->and($change->fresh()->status)->toBe('approved')
        ->and(Change::query()->where('kind', 'field.add')->where('status', 'applied')->exists())->toBeTrue();
});

it('rejects a requested change without applying it', function () {
    promotePrimaryTenant();
    $module = studioModule('reject');

    $change = Change::create([
        'kind' => 'field.add',
        'target_module' => $module->key,
        'target_field' => 'nickname',
        'payload' => [
            'action' => 'add',
            'module_key' => $module->key,
            'name' => 'nickname',
            'type' => 'text',
        ],
        'status' => 'requested',
    ]);

    app(StudioApprovalService::class)->reject($change, reviewerId: null, note: 'Not needed');

    expect(Field::query()->where('module_id', $module->id)->where('name', 'nickname')->exists())->toBeFalse()
        ->and($change->fresh()->status)->toBe('rejected')
        ->and($change->fresh()->review_note)->toBe('Not needed');
});

it('platform resource reads back the Studio mode a tenant was given', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['studio_mode' => StudioGovernance::MODE_REQUEST_ONLY]);

    expect(StudioGovernance::modeOf($tenant->fresh()))->toBe(StudioGovernance::MODE_REQUEST_ONLY);
});
