<?php

use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Widgets\ActivityTimelineWidget;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Z-4.4 -- N+1 elimination on the dynamic rendering path. ApiPerformanceTest.php
// already covers the REST API path; these cover the two Filament-admin-UI N+1s
// this session found and fixed: the owner column on every dynamic list table
// (BuildsResourceFromMetadata::table()), and createdBy/assignedUser on every
// activity timeline row (HasActivities' six relations).
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('does not add one query per row for the owner column on a dynamic list table', function () {
    Lead::factory()->count(15)->create();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    Livewire::test(ListLeads::class)->assertSuccessful();

    // Without eager-loading assignedUser, 15 rows would add 15 extra
    // queries on top of the table's own handful (count, select, etc.) --
    // a generous fixed ceiling here, not a per-row one, is what proves it.
    expect($queryCount)->toBeLessThan(15);
});

it('does not add two queries per activity item for createdBy/assignedUser on the timeline', function () {
    $lead = Lead::factory()->create();
    $owner = User::factory()->create();

    foreach (range(1, 8) as $i) {
        Meeting::create([
            'subject_type' => Lead::class, 'subject_id' => $lead->id,
            'assigned_user_id' => $owner->id, 'created_by' => $owner->id,
            'name' => "Meeting {$i}", 'date_start' => now()->subHours($i), 'status' => 'planned',
        ]);
        Task::create([
            'subject_type' => Lead::class, 'subject_id' => $lead->id,
            'assigned_user_id' => $owner->id, 'created_by' => $owner->id,
            'name' => "Task {$i}", 'status' => 'in_progress', 'priority' => 'medium',
        ]);
    }

    $widget = new ActivityTimelineWidget;
    $widget->record = $lead->fresh();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $records = $widget->records();

    // 16 activity items (8 meetings + 8 tasks) each read createdBy AND
    // assignedUser -- unfixed, that's 32 extra queries on top of the
    // baseline. Eager-loaded, the total stays fixed regardless of item
    // count: one relation query per activity type (6, even when empty)
    // plus one eager-load query per createdBy/assignedUser pair per
    // non-empty type (2 types here) plus the audit query -- nowhere near
    // scaling with the 16 items themselves.
    expect($records)->toHaveCount(17) // 16 activities + the Lead's own "created" audit
        ->and($queryCount)->toBeLessThan(20);
});
