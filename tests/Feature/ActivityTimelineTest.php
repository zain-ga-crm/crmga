<?php

use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Filament\Widgets\ActivityTimelineWidget;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Support\Filament\ActivityFeedFormatter;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

// S-4.3: the unified activity feed. ActivityFeedFormatter is the pure
// normalisation logic (one item -> one display row); ActivityTimelineWidget
// is the record-scoped footer widget that merges HasActivities::activityFeed()
// with the record's own laravel-auditing rows. "Field changes" reads
// Audit, not App\Models\Metadata\Change -- see the formatter's own docblock.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('formats a meeting, a task and a field-change audit into a common display row', function () {
    $lead = Lead::factory()->create();

    $meeting = Meeting::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'name' => 'Intake call', 'date_start' => now(), 'status' => 'planned',
    ]);
    $meetingRow = ActivityFeedFormatter::describe($meeting);
    expect($meetingRow['type'])->toBe('Meeting')
        ->and($meetingRow['title'])->toBe('Intake call')
        ->and($meetingRow['who'])->toBe('System');

    $task = Task::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'name' => 'Follow up', 'status' => 'in_progress', 'priority' => 'high',
    ]);
    $taskRow = ActivityFeedFormatter::describe($task);
    expect($taskRow['type'])->toBe('Task')
        ->and($taskRow['summary'])->toBe('high priority, in_progress');

    $lead->update(['source' => 'Referral']);
    /** @var Audit $audit */
    $audit = Audit::query()->where('auditable_id', $lead->id)->where('event', 'updated')->latest()->firstOrFail();
    $auditRow = ActivityFeedFormatter::describe($audit);
    expect($auditRow['type'])->toBe('Field change')
        ->and($auditRow['title'])->toContain('Updated')
        ->and($auditRow['title'])->toContain('source');
});

it('merges activities and field changes into one feed, newest first', function () {
    $lead = Lead::factory()->create();

    Call::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'direction' => 'outbound', 'date_start' => now()->subDays(2), 'outcome' => 'no_answer',
    ]);
    $lead->update(['stage' => 'contacted']);

    $widget = new ActivityTimelineWidget;
    $widget->record = $lead->fresh();

    $records = $widget->records();

    expect($records)->not->toBeEmpty()
        ->and(collect($records)->pluck('type')->all())->toContain('Call', 'Field change');

    $whens = collect($records)->pluck('when');
    expect($whens->values()->all())->toEqual($whens->sortDesc()->values()->all());
});

it('shows only the creation audit -- itself a field change -- for a record with no other activity', function () {
    $widget = new ActivityTimelineWidget;
    $widget->record = Lead::factory()->create();

    $records = $widget->records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['type'])->toBe('Field change')
        ->and($records[0]['title'])->toStartWith('Created:');
});

it('renders the activity timeline widget directly with a real record\'s activity, per Filament\'s lazy-loaded footer widgets', function () {
    // ViewRecord's footer widgets are Filament\Support\Concerns\CanBeLazy
    // (lazy by default) -- Livewire::test() on the PAGE only ever sees the
    // placeholder, never the nested lazy widget's real content, so this
    // exercises the widget itself, same as every other dashboard widget
    // test in DashboardWidgetsTest.php.
    $lead = Lead::factory()->create();
    Meeting::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'name' => 'Discovery meeting', 'date_start' => now(), 'status' => 'planned',
    ]);

    Livewire::test(ActivityTimelineWidget::class, ['record' => $lead])
        ->assertSuccessful()
        ->assertSee('Activity timeline')
        ->assertSee('Discovery meeting');
});

it('mounts the lead view page successfully with its footer widget registered', function () {
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertSuccessful();
});
