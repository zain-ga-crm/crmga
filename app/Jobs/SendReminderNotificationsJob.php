<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\ReminderNotification;
use App\Support\NotificationDedupGuard;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Task and follow-up reminders, run every 15 minutes (BACKEND_BRIEF §11) but
 * gated to `business_hours` settings and deduplicated per subject per day
 * (via NotificationDedupGuard) -- without that guard, anything still overdue
 * would be re-notified on every single run instead of once a day. Runs with
 * no authenticated user, so the ACL-scoped models (Lead, Client) must bypass
 * AppliesRecordAccess -- this reads across every owner's records to notify
 * each one, it is not acting on behalf of a single signed-in user.
 */
final class SendReminderNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Settings $settings, NotificationDedupGuard $guard): void
    {
        $timezoneRaw = $settings->get('system.timezone', config('app.timezone'));
        $timezone = is_string($timezoneRaw) ? $timezoneRaw : 'UTC';
        $now = Carbon::now($timezone);

        if (! $this->isWithinBusinessHours($settings, $now)) {
            return;
        }

        $today = $now->toDateString();

        Task::query()
            ->whereNotNull('assigned_user_id')
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $now)
            ->with('assignedUser')
            ->each(function (Task $task) use ($guard, $today): void {
                if (! $guard->claim("follow_up:task:{$task->id}:{$today}")) {
                    return;
                }

                $task->assignedUser?->notify(new ReminderNotification(
                    reason: 'task_due',
                    subjectType: Task::class,
                    subjectId: $task->id,
                    label: $task->name,
                    dueAt: Carbon::parse($task->due_date),
                ));
            });

        Lead::withoutGlobalScopes()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $now)
            ->with('assignedUser')
            ->each(function (Lead $lead) use ($guard, $today): void {
                if ($lead->next_follow_up_at === null || ! $guard->claim("follow_up:lead:{$lead->id}:{$today}")) {
                    return;
                }

                $lead->assignedUser?->notify(new ReminderNotification(
                    reason: 'follow_up_due',
                    subjectType: Lead::class,
                    subjectId: $lead->id,
                    label: $lead->fullName(),
                    dueAt: $lead->next_follow_up_at,
                ));
            });

        Client::withoutGlobalScopes()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', $now)
            ->with('assignedUser')
            ->each(function (Client $client) use ($guard, $today): void {
                if ($client->next_action_at === null || ! $guard->claim("follow_up:client:{$client->id}:{$today}")) {
                    return;
                }

                $client->assignedUser?->notify(new ReminderNotification(
                    reason: 'follow_up_due',
                    subjectType: Client::class,
                    subjectId: $client->id,
                    label: $client->fullName(),
                    dueAt: $client->next_action_at,
                ));
            });
    }

    /**
     * Default Mon-Fri 09:00-17:00 company time zone (BACKEND_BRIEF §21 open
     * question #10's own stated default).
     */
    private function isWithinBusinessHours(Settings $settings, Carbon $now): bool
    {
        $days = $settings->get('business_hours.days', [1, 2, 3, 4, 5]);
        if (! is_array($days) || ! in_array($now->dayOfWeekIso, $days, true)) {
            return false;
        }

        $startRaw = $settings->get('business_hours.start', '09:00');
        $endRaw = $settings->get('business_hours.end', '17:00');
        $start = is_string($startRaw) ? $startRaw : '09:00';
        $end = is_string($endRaw) ? $endRaw : '17:00';

        $time = $now->format('H:i');

        return $time >= $start && $time <= $end;
    }
}
