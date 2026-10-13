<?php

use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\SavedView;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-2.4: the one piece of the list-page framework (saved views, column
// chooser, filter panel, bulk-action bar, export) that hadn't been built.
// Exercised on LeadResource only, same as most other shared-mechanism
// coverage in this app (BuildsResourceFromMetadata itself) -- the trait is
// identical across all seven ListRecords pages that use it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('saves the current table filters, search and sort as a named view', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->set('tableSearch', 'amina')
        ->set('tableSortColumn', 'created_at')
        ->set('tableSortDirection', 'desc')
        ->callAction('saveView', data: ['name' => 'My hot leads'])
        ->assertHasNoActionErrors();

    $view = SavedView::query()->where('user_id', $user->id)->where('module_key', 'leads')->first();
    expect($view)->not->toBeNull()
        ->and($view->name)->toBe('My hot leads')
        ->and($view->search)->toBe('amina')
        ->and($view->sort_column)->toBe('created_at')
        ->and($view->sort_direction)->toBe('desc');
});

it('re-saving under the same name updates the existing view instead of duplicating it', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->set('tableSearch', 'first')
        ->callAction('saveView', data: ['name' => 'Mine']);

    Livewire::test(ListLeads::class)
        ->set('tableSearch', 'second')
        ->callAction('saveView', data: ['name' => 'Mine']);

    expect(SavedView::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SavedView::query()->where('user_id', $user->id)->first()->search)->toBe('second');
});

it('applies a saved view\'s search back onto the table', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $view = SavedView::factory()->create([
        'user_id' => $user->id,
        'module_key' => 'leads',
        'name' => 'Needs follow-up',
        'search' => 'khan',
        'filters' => [],
    ]);
    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->callAction('applyView', data: ['saved_view_id' => $view->id])
        ->assertHasNoActionErrors()
        ->assertSet('tableSearch', 'khan');
});

it('deletes a saved view', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $view = SavedView::factory()->create(['user_id' => $user->id, 'module_key' => 'leads']);
    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->callAction('deleteView', data: ['saved_view_id' => $view->id])
        ->assertHasNoActionErrors();

    expect(SavedView::query()->whereKey($view->id)->exists())->toBeFalse();
});

it('never shows another user\'s saved views', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create(['is_admin' => true]);
    SavedView::factory()->create(['user_id' => $owner->id, 'module_key' => 'leads', 'name' => 'Owner\'s view']);
    $this->actingAs($other);

    Livewire::test(ListLeads::class)
        ->assertActionHidden('applyView')
        ->assertActionHidden('deleteView');
});

it('scopes saved views per module -- a view saved on one module never leaks into another', function () {
    $user = User::factory()->create(['is_admin' => true]);
    SavedView::factory()->create(['user_id' => $user->id, 'module_key' => 'companies', 'name' => 'Companies view']);
    $this->actingAs($user);

    Livewire::test(ListLeads::class)
        ->assertActionHidden('applyView')
        ->assertActionHidden('deleteView');

    expect(Lead::query()->count())->toBe(0);
});
