<?php

use App\Models\Lead;
use App\Models\Metadata\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

/**
 * BACKEND_BRIEF §4: "New records use UUIDv7 (time-ordered, better index
 * locality)." Every model used stock HasUuids (v4, fully random) instead --
 * switched to HasVersion7Uuids, a drop-in Laravel 11 trait with the same
 * public interface. Existing v4 ids in the database are unaffected; this
 * only changes what new records get.
 *
 * A v7 UUID's version nibble (the first hex digit of the third group) is
 * always '7' -- xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx.
 */
it('generates a version-7 UUID for a new User', function () {
    expect(User::factory()->create()->id[14])->toBe('7');
});

it('generates a version-7 UUID for a new Lead', function () {
    expect(Lead::factory()->create()->id[14])->toBe('7');
});

it('generates a version-7 UUID for a new metadata Module', function () {
    expect(Module::factory()->create()->id[14])->toBe('7');
});
