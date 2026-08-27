<?php

use App\Jobs\SendDailyLeadCountReportJob;
use App\Jobs\SendDailyStudentCountReportJob;
use App\Jobs\SendReminderNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Z-3.3: keep the schema-change snapshot disk bounded (BACKEND_BRIEF §6 per-installation limit).
Schedule::command('schema:prune-snapshots')->daily();

// Z-4.2: scheduled jobs and notifications. All run on the queue (Horizon in
// production, the database driver locally/CI) rather than inline on the scheduler.
// BACKEND_BRIEF §11: lead count at 10:07 (cron "7 10 * * *") and student count at
// 10:12 ("12 10 * * *") are two separate notifications, not one merged report;
// reminders run every 15 minutes (the job itself gates on business_hours and
// dedupes per subject/day).
Schedule::job(new SendDailyLeadCountReportJob)->dailyAt('10:07')->withoutOverlapping();
Schedule::job(new SendDailyStudentCountReportJob)->dailyAt('10:12')->withoutOverlapping();
Schedule::job(new SendReminderNotificationsJob)->everyFifteenMinutes()->withoutOverlapping();

// Z-7.3: BACKEND_BRIEF's own open-question default -- "nightly dump to
// object storage." withoutOverlapping guards against a slow dump still
// running when the next night's fires.
Schedule::command('crm:backup')->dailyAt('02:00')->withoutOverlapping();
