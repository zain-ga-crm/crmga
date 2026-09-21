<?php

use App\Filament\Pages\HotWarmList;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.5: the Hot/Warm aggregate view. Thin wiring over
// ContactableModuleRegistry, same shape as DoNotCallListTest.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('allows the page to any authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(HotWarmList::getUrl())->assertSuccessful();
});

it('lists hot leads by default', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $hot = Lead::factory()->create(['hot_lead' => true, 'warm_lead' => false]);
    Lead::factory()->create(['hot_lead' => false, 'warm_lead' => false]);

    $this->actingAs($admin);

    Livewire::test(HotWarmList::class)
        ->set('moduleKey', 'leads')
        ->assertCanSeeTableRecords([$hot]);
});

it('switches to warm leads when the temperature toggle changes', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $hot = Lead::factory()->create(['hot_lead' => true, 'warm_lead' => false]);
    $warm = Lead::factory()->create(['hot_lead' => false, 'warm_lead' => true]);

    $this->actingAs($admin);

    Livewire::test(HotWarmList::class)
        ->set('moduleKey', 'leads')
        ->set('temperature', 'warm')
        ->assertCanSeeTableRecords([$warm])
        ->assertCanNotSeeTableRecords([$hot]);
});

it('only offers the modules that register hot_lead/warm_lead', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $options = Livewire::test(HotWarmList::class)->instance()->moduleOptions();

    expect($options)->toHaveKeys(['leads', 'companies', 'students'])
        ->and($options)->not->toHaveKey('affiliates');
});
