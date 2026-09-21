<?php

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Widgets\AttentionNeededWidget;
use App\Filament\Widgets\CallsToMakeWidget;
use App\Filament\Widgets\HotWarmByVerticalWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\PipelineByStageWidget;
use App\Filament\Widgets\TodaysMeetingsWidget;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-4.4: the dashboard widget layer over Z-4.3's already-tested
// DashboardService (see DashboardServiceTest.php for the aggregation logic
// itself) plus two widgets (MyTasks/TodaysMeetings) that query Task/Meeting
// directly, since those aren't part of DashboardService's scope.
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('allows the hot/warm and pipeline chart widgets to an admin, and hides them from a user with no leads access', function () {
    expect(HotWarmByVerticalWidget::canView())->toBeTrue()
        ->and(PipelineByStageWidget::canView())->toBeTrue()
        ->and(CallsToMakeWidget::canView())->toBeTrue();

    $this->actingAs(User::factory()->create());

    expect(HotWarmByVerticalWidget::canView())->toBeFalse()
        ->and(PipelineByStageWidget::canView())->toBeFalse()
        ->and(CallsToMakeWidget::canView())->toBeFalse();
});

it('mounts the chart widgets without error', function () {
    Livewire::test(HotWarmByVerticalWidget::class)->assertOk();
    Livewire::test(PipelineByStageWidget::class)->assertOk();
});

it('lists only today\'s calls in the calls-to-make widget, with a working view link', function () {
    // Pinned for the same reason as the todays-meetings widget test below --
    // addHours(2) against the real clock can cross midnight and flake this
    // near the end of a day.
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-01-15 09:00:00'));

    $today = Lead::factory()->create(['next_follow_up_at' => now()->addHours(2)]);
    $tomorrow = Lead::factory()->create(['next_follow_up_at' => now()->addDay()]);

    Livewire::test(CallsToMakeWidget::class)
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$tomorrow])
        ->assertTableActionHasUrl('view', LeadResource::getUrl('view', ['record' => $today]), record: $today);

    Carbon\Carbon::setTestNow();
});

it('lists overdue leads and clients in the attention-needed widget, sorted, with resolvable view urls', function () {
    $overdueLead = Lead::factory()->create(['next_follow_up_at' => now()->subDays(2)]);
    $overdueClient = Client::factory()->create(['next_action_at' => now()->subDay()]);
    Lead::factory()->create(['next_follow_up_at' => now()->addHour()]);

    $records = (new AttentionNeededWidget)->records();
    $ids = collect($records)->pluck('id');

    expect($ids)->toContain($overdueLead->id)
        ->and($ids)->toContain($overdueClient->id)
        ->and($records)->toHaveCount(2)
        ->and(collect($records)->firstWhere('id', $overdueLead->id)['url'])
        ->toBe(LeadResource::getUrl('view', ['record' => $overdueLead]))
        ->and(collect($records)->firstWhere('id', $overdueClient->id)['url'])
        ->toBe(ClientResource::getUrl('view', ['record' => $overdueClient]));
});

it('shows nothing in the attention-needed widget when nothing is overdue', function () {
    expect((new AttentionNeededWidget)->records())->toBe([]);
});

it('lists only my own open tasks in the my-tasks widget', function () {
    $me = auth()->user();
    $subject = Lead::factory()->create();
    $base = ['subject_type' => Lead::class, 'subject_id' => $subject->id];
    $myOpenTask = Task::create([...$base, 'assigned_user_id' => $me->id, 'name' => 'Call the lead back', 'status' => 'in_progress', 'priority' => 'high']);
    $myCompletedTask = Task::create([...$base, 'assigned_user_id' => $me->id, 'name' => 'Already done', 'status' => 'completed', 'priority' => 'low']);
    $someoneElsesTask = Task::create([...$base, 'assigned_user_id' => User::factory()->create()->id, 'name' => 'Not mine', 'status' => 'in_progress', 'priority' => 'medium']);

    Livewire::test(MyTasksWidget::class)
        ->assertCanSeeTableRecords([$myOpenTask])
        ->assertCanNotSeeTableRecords([$myCompletedTask, $someoneElsesTask]);
});

it('lists only my own meetings happening today in the todays-meetings widget', function () {
    // Pinned to a fixed mid-day instant, not real now() -- addHours(3)/addDay()
    // against the real clock can cross midnight depending on when the suite
    // happens to run, silently moving "today" into "tomorrow" and flaking
    // this test only in a full-suite run late at night (caught exactly that
    // way once already).
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-01-15 09:00:00'));

    $me = auth()->user();
    $subject = Lead::factory()->create();
    $base = ['subject_type' => Lead::class, 'subject_id' => $subject->id];
    $myMeetingToday = Meeting::create([...$base, 'assigned_user_id' => $me->id, 'name' => 'Client call', 'date_start' => now()->addHours(3), 'status' => 'planned']);
    $myMeetingTomorrow = Meeting::create([...$base, 'assigned_user_id' => $me->id, 'name' => 'Next week', 'date_start' => now()->addDay(), 'status' => 'planned']);
    $someoneElsesMeetingToday = Meeting::create([...$base, 'assigned_user_id' => User::factory()->create()->id, 'name' => 'Not mine', 'date_start' => now()->addHours(1), 'status' => 'planned']);

    Livewire::test(TodaysMeetingsWidget::class)
        ->assertCanSeeTableRecords([$myMeetingToday])
        ->assertCanNotSeeTableRecords([$myMeetingTomorrow, $someoneElsesMeetingToday]);

    Carbon\Carbon::setTestNow();
});
