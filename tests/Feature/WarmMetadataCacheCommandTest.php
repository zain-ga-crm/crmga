<?php

use App\Support\MetadataRepository;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

it('warms the cache store so a fresh MetadataRepository instance never hits the database', function () {
    $this->seed(MetadataFixtureSeeder::class);

    $this->artisan('crm:metadata:warm')->assertExitCode(0);

    // A genuinely new instance, not the container's singleton (which would
    // already have its own in-memory $compiledCache warm from the command's
    // own call regardless of whether the underlying cache *store* is
    // populated) -- this is what a fresh request process in production
    // would resolve, so it's the only way to prove the store itself, not
    // just this process's memoized copy, is warm.
    $fresh = new MetadataRepository;

    DB::enableQueryLog();
    $compiled = $fresh->compiled();
    $queries = DB::getQueryLog();
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(0)
        ->and($compiled['modules'] ?? null)->not->toBeNull();
});
