<?php

use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Support\LayoutValidator;
use App\Support\MetadataRepository;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;

uses(DatabaseTruncation::class);

beforeEach(fn () => Cache::flush());

it('compiles the seeded leads module and its option lists', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $meta = app(MetadataRepository::class)->compiled();

    expect($meta['modules'])->toHaveKey('leads')
        ->and($meta['modules']['leads']['fields'])
        ->toHaveKeys(['full_name', 'vertical', 'stage', 'primary_email', 'phone_mobile'])
        ->and($meta['option_lists'])->toHaveKeys(['lead_vertical', 'lead_stage'])
        ->and($meta['option_lists']['lead_stage']['items'])->toContain(['value' => 'follow_up', 'label' => 'Follow up', 'color' => 'warning']);
});

it('carries each option item\'s S-1.4 badge color through to the compiled structure', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $meta = app(MetadataRepository::class)->compiled();
    $stageItems = collect($meta['option_lists']['lead_stage']['items'])->keyBy('value');

    expect($stageItems['converted']['color'])->toBe('success')
        ->and($stageItems['lost']['color'])->toBe('danger')
        // lead_vertical deliberately has no colour progression -- every item
        // stays null, using Filament's own default badge styling.
        ->and(collect($meta['option_lists']['lead_vertical']['items'])->pluck('color')->unique()->all())->toBe([null]);
});

it('gives every seeded field a real label and every module a label_plural', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $leads = Module::query()->where('key', 'leads')->firstOrFail();

    expect($leads->label_plural)->toBe('Leads')
        ->and($leads->fields()->whereNull('label')->exists())->toBeFalse()
        ->and($leads->fields()->where('name', 'primary_email')->value('label'))->toBe('Primary email');
});

it('defaults a seeded option item to active, and a module can be soft-deleted', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $item = OptionItem::query()->whereHas('optionList', fn ($q) => $q->where('key', 'lead_stage'))->firstOrFail();
    expect($item->is_active)->toBeTrue();

    $leads = Module::query()->where('key', 'leads')->firstOrFail();
    $leads->delete();

    expect(Module::query()->where('key', 'leads')->exists())->toBeFalse()
        ->and(Module::withTrashed()->where('key', 'leads')->exists())->toBeTrue();
});

it('seeds layouts that satisfy the frozen contract', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $validator = app(LayoutValidator::class);

    $leads = Module::query()->where('key', 'leads')->firstOrFail();

    expect($leads->layouts)->toHaveCount(4)
        ->and($leads->layouts->pluck('view')->sort()->values()->all())->toBe(['detail', 'edit', 'list', 'search']);
    foreach ($leads->layouts as $layout) {
        expect($validator->errors($layout->definition))->toBe([]);
    }
});

it('rejects a layout that violates the contract', function () {
    $bad = [
        'version' => 1,
        'view' => 'list',
        'module' => 'leads',
        'content' => ['columns' => [['nope' => 'x']]],   // missing required 'field', extra prop
    ];

    expect(app(LayoutValidator::class)->valid($bad))->toBeFalse();
});

it('bumps the version on any metadata change', function () {
    $repo = app(MetadataRepository::class);
    $before = $repo->version();

    Module::factory()->create();

    expect($repo->version())->toBeGreaterThan($before);
});

it('caches the compiled structure per version', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $repo = app(MetadataRepository::class);

    expect($repo->compiled())->toBe($repo->compiled())
        ->and($repo->compiled()['version'])->toBe($repo->version());
});

it('invalidates the in-memory compiled() memo on bump(), so a change is visible within the same request', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $repo = app(MetadataRepository::class);

    $before = $repo->compiled();
    Module::factory()->create(['key' => 'a_new_module']); // triggers Module::saved -> bump()
    $after = $repo->compiled();

    expect($after['version'])->toBeGreaterThan($before['version'])
        ->and($after['modules'])->toHaveKey('a_new_module');
});
