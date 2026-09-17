<?php

use App\Models\Lead;
use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Models\User;
use App\Support\SchemaManager\SchemaManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Real bug found while dogfooding S-2.1 live: SchemaManager::createSidecarSql()
// gives every {table}_custom sidecar the same 'id' primary key name as the
// base table (see its own DDL), but HasCustomFields' read/write path was
// hardcoded to a distinct 'id_c' column that SchemaManager never creates --
// every custom-field lookup silently 404'd (empty row -> null attribute) or,
// once the local sidecar already had unrelated columns from earlier real
// usage, threw "Unknown column 'id_c'" outright. No existing test exercised
// a real round trip through the sidecar with a real is_custom field, so this
// had zero coverage before now.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    // Z-2.3 wires Lead into the ACL engine (HasAcl) -- an unauthenticated
    // request scopes every query to zero rows (AppliesRecordAccess), which
    // isn't what these tests are about.
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    if (! Schema::hasTable('leads_custom')) {
        app(SchemaManager::class)->createSidecar('leads_custom');
    }

    if (! Schema::hasColumn('leads_custom', 'favorite_color')) {
        Schema::table('leads_custom', fn ($table) => $table->string('favorite_color', 50)->nullable());
    }
});

it('round-trips a Studio custom field through the sidecar via the same id column SchemaManager creates', function () {
    $module = Module::factory()->create(['key' => 'leads', 'table_name' => 'leads']);
    Field::factory()->for($module)->create([
        'name' => 'favorite_color',
        'type' => 'text',
        'storage' => 'column',
        'is_custom' => true,
    ]);

    $lead = Lead::factory()->create();
    $lead->favorite_color = 'Teal';
    $lead->save();

    // Read back through a genuinely fresh model instance, not the one that
    // just wrote it, so this exercises mergeCustomAttributesFromSidecar()'s
    // own read path, not just the in-memory value set moments ago.
    $fresh = Lead::query()->find($lead->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->favorite_color)->toBe('Teal');

    $row = DB::table('leads_custom')->where('id', $lead->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->favorite_color)->toBe('Teal');
});

it('does not error when a lead has no matching sidecar row yet', function () {
    $module = Module::factory()->create(['key' => 'leads', 'table_name' => 'leads']);
    Field::factory()->for($module)->create([
        'name' => 'favorite_color',
        'type' => 'text',
        'storage' => 'column',
        'is_custom' => true,
    ]);

    // A lead created before this field existed, or whose sidecar row was
    // never written -- reading it back must not throw.
    $lead = Lead::factory()->create();
    DB::table('leads_custom')->where('id', $lead->id)->delete();

    $fresh = Lead::query()->find($lead->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->favorite_color)->toBeNull();
});
