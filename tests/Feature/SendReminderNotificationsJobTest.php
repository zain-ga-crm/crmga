<?php

use App\Jobs\SendReminderNotificationsJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ReminderNotification;
use App\Support\NotificationDedupGuard;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Fixtures\ContactableFixture;

uses(DatabaseTruncation::class);

// A fixed Wednesday within the Mon-Fri 09:00-17:00 default business-hours
// window (BACKEND_BRIEF §21 open question #10), so these tests aren't at the
// mercy of whatever real wall-clock time CI happens to run at.
beforeEach(function () {
    Carbon::setTestNow('2026-08-26 10:00:00');
    $this->run = fn () => app(SendReminderNotificationsJob::class)
        ->handle(app(Settings::class), app(NotificationDedupGuard::class));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('notifies the assigned user of an overdue task, but not a completed one', function () {
    Notification::fake();

    $user = User::factory()->create();
    $overdue = Task::create([
        'subject_type' => ContactableFixture::class,
        'subject_id' => (string) Str::uuid(),
        'assigned_user_id' => $user->id,
        'name' => 'Send documents',
        'due_date' => now()->subDay(),
        'status' => 'not_started',
    ]);
    Task::create([
        'subject_type' => ContactableFixture::class,
        'subject_id' => (string) Str::uuid(),
        'assigned_user_id' => $user->id,
        'name' => 'Already done',
        'due_date' => now()->subDay(),
        'status' => 'completed',
    ]);

    ($this->run)();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $overdue->id && $n->reason === 'task_due',
    );
    Notification::assertSentToTimes($user, ReminderNotification::class, 1);
});

it('notifies the assigned user of a lead whose follow-up is due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $lead = Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->addDay(),
    ]);

    ($this->run)();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $lead->id && $n->reason === 'follow_up_due',
    );
    Notification::assertSentToTimes($user, ReminderNotification::class, 1);
});

it('notifies the assigned user of a client whose next action is due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $client = Client::factory()->create([
        'assigned_user_id' => $user->id,
        'next_action_at' => now()->subHour(),
    ]);

    ($this->run)();

    Notification::assertSentTo(
        $user,
        ReminderNotification::class,
        fn (ReminderNotification $n) => $n->subjectId === $client->id && $n->reason === 'follow_up_due',
    );
});

it('writes a real in-app notification row, not just the fake assertion', function () {
    $user = User::factory()->create();
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);

    ($this->run)();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->unreadNotifications()->count())->toBe(1);
});

it('does not re-notify the same overdue lead on a second run within the same day', function () {
    Notification::fake();

    $user = User::factory()->create();
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);

    ($this->run)();
    ($this->run)();

    Notification::assertSentToTimes($user, ReminderNotification::class, 1);
});

it('sends nothing outside business hours', function () {
    Carbon::setTestNow('2026-08-26 20:00:00'); // still Wednesday, just after 17:00
    Notification::fake();

    $user = User::factory()->create();
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);

    ($this->run)();

    Notification::assertNothingSent();
});

it('sends nothing on a weekend', function () {
    Carbon::setTestNow('2026-08-29 10:00:00'); // a Saturday
    Notification::fake();

    $user = User::factory()->create();
    Lead::factory()->create([
        'assigned_user_id' => $user->id,
        'next_follow_up_at' => now()->subHour(),
    ]);

    ($this->run)();

    Notification::assertNothingSent();
});
