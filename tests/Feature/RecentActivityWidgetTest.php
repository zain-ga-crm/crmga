<?php

use App\Filament\Resources\LeadResource;
use App\Filament\Widgets\ActivityTimelineWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-4.4's 8th widget, deferred until S-4.3's ActivityFeedFormatter existed.
// "My recent activity", not "everyone's recent activity" -- same
// assigned_user_id scoping as MyTasksWidget/TodaysMeetingsWidget, since
// none of the six HasActivities models carry a module-level ACL of their
// own to scope by instead.
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('lists only my own recent activity across every activity type, newest first, with a link to the subject', function () {
    $me = auth()->user();
    $someoneElse = User::factory()->create();
    $lead = Lead::factory()->create();
    $base = ['subject_type' => Lead::class, 'subject_id' => $lead->id];

    $mine = Meeting::create([...$base, 'assigned_user_id' => $me->id, 'name' => 'My meeting', 'date_start' => now()->subHour(), 'status' => 'planned']);
    Task::create([...$base, 'assigned_user_id' => $someoneElse->id, 'name' => 'Not mine', 'status' => 'not_started', 'priority' => 'low']);

    $widget = new RecentActivityWidget;
    $records = $widget->records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['title'])->toBe('My meeting')
        ->and($records[0]['subjectLabel'])->toBe($lead->fullName())
        ->and($records[0]['url'])->toBe(LeadResource::getUrl('view', ['record' => $lead]));
});

it('shows nothing when I have no recent activity', function () {
    expect((new RecentActivityWidget)->records())->toBe([]);
});

it('is not panel-wide discoverable, unlike the per-record activity timeline widget', function () {
    // ActivityTimelineWidget only makes sense with a $record (bound via
    // HasActivityTimelineFooter on a ViewRecord page) -- without
    // isDiscovered = false it would also appear, permanently empty, on
    // every page that lists panel widgets, including this dashboard.
    expect(RecentActivityWidget::isDiscovered())->toBeTrue()
        ->and(ActivityTimelineWidget::isDiscovered())->toBeFalse();
});

it('renders the widget successfully on the dashboard', function () {
    Livewire::test(RecentActivityWidget::class)->assertSuccessful();
});
