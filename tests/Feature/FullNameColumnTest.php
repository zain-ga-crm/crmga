<?php

use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

/**
 * Regression coverage for a bug found while building S-2.4 (saved views):
 * `full_name` is registered as a plain `type: text` metadata field on every
 * Contactable module and used as the title/link column on every list and
 * detail layout, but there is no real `full_name` column or Eloquent
 * accessor (only the Contactable::fullName() method). Built generically,
 * the column/entry rendered permanently blank, and the table's own search
 * box or a click on the column header to sort threw "Unknown column
 * 'full_name'" -- confirmed live against the real leads list before the
 * fix (FieldTypeRegistry::fullNameTableColumn()/fullNameInfolistEntry()).
 * Exercised on Lead only; the mechanism is identical on all six modules.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('renders the full name on the list, not a blank cell', function () {
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$lead])
        ->assertSee('Amina Khan');
});

it('renders the full name on the detail view', function () {
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Amina Khan');
});

it('searches the list by full name without throwing an SQL error', function () {
    $match = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);
    $noMatch = Lead::factory()->create(['first_name' => 'Zane', 'last_name' => 'Okafor']);

    Livewire::test(ListLeads::class)
        ->set('tableSearch', 'amina')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('searches the list by last name too', function () {
    $match = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);
    $noMatch = Lead::factory()->create(['first_name' => 'Zane', 'last_name' => 'Okafor']);

    Livewire::test(ListLeads::class)
        ->set('tableSearch', 'khan')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('sorts the list by full name (last name, then first) without throwing an SQL error', function () {
    $zane = Lead::factory()->create(['first_name' => 'Zane', 'last_name' => 'Okafor']);
    $amina = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    Livewire::test(ListLeads::class)
        ->sortTable('full_name')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$amina, $zane], inOrder: true);
});
