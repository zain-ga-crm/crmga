<?php

use App\Jobs\SendFailedJobAlertJob;
use App\Mail\FailedJobAlertMail;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    // See SendDailyLeadCountReportJobTest.php: Settings caches its compiled
    // map under a fixed key that DatabaseTruncation's table reset doesn't
    // clear, and this app keeps one booted app across tests (Z-8.3).
    app(Settings::class)->flush();

    $this->run = fn () => app(SendFailedJobAlertJob::class)->handle(app(Settings::class));
});

function insertFailedJob(Carbon $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Exception: test',
        'failed_at' => $failedAt,
    ]);
}

it('is a no-op until alert recipients are configured, even with failures over threshold', function () {
    Mail::fake();
    for ($i = 0; $i < 10; $i++) {
        insertFailedJob(now());
    }

    ($this->run)();

    Mail::assertNothingSent();
});

it('does not alert while failures in the last hour are under the threshold', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.failed_job_alert_recipients', ['ops@gunness.test']);
    app(Settings::class)->set('notifications.failed_job_alert_threshold', 5);

    for ($i = 0; $i < 4; $i++) {
        insertFailedJob(now());
    }

    ($this->run)();

    Mail::assertNothingSent();
});

it('alerts once failures in the last hour reach the threshold', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.failed_job_alert_recipients', ['ops@gunness.test']);
    app(Settings::class)->set('notifications.failed_job_alert_threshold', 3);

    for ($i = 0; $i < 3; $i++) {
        insertFailedJob(now());
    }

    ($this->run)();

    Mail::assertSent(FailedJobAlertMail::class, function (FailedJobAlertMail $mail) {
        return $mail->hasTo('ops@gunness.test')
            && $mail->count === 3
            && $mail->threshold === 3;
    });
});

it('ignores failures older than an hour', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.failed_job_alert_recipients', ['ops@gunness.test']);
    app(Settings::class)->set('notifications.failed_job_alert_threshold', 2);

    insertFailedJob(now()->subHours(2));
    insertFailedJob(now()->subHours(3));

    ($this->run)();

    Mail::assertNothingSent();
});

it('defaults the threshold to 5 when not configured', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.failed_job_alert_recipients', ['ops@gunness.test']);

    for ($i = 0; $i < 4; $i++) {
        insertFailedJob(now());
    }
    ($this->run)();
    Mail::assertNothingSent();

    insertFailedJob(now());
    ($this->run)();
    Mail::assertSent(FailedJobAlertMail::class);
});
