<?php

use App\Jobs\SendDailyCountReportJob;
use App\Mail\DailyCountReportMail;
use App\Models\Lead;
use App\Models\Student;
use App\Support\NotificationDedupGuard;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->run = fn () => app(SendDailyCountReportJob::class)
        ->handle(app(Settings::class), app(NotificationDedupGuard::class));
});

it('is a no-op until report recipients are configured', function () {
    Mail::fake();

    ($this->run)();

    Mail::assertNothingSent();
});

it('emails the configured recipients with today\'s lead and student counts', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.daily_report_recipients', ['ops@gunness.test']);

    Lead::factory()->create(['hot_lead' => true]);
    Lead::factory()->create(['stage' => 'lost']);
    Student::factory()->create();

    ($this->run)();

    Mail::assertSent(DailyCountReportMail::class, function (DailyCountReportMail $mail) {
        return $mail->hasTo('ops@gunness.test')
            && $mail->counts['total_leads'] === 2
            && $mail->counts['hot_leads'] === 1
            && $mail->counts['open_leads'] === 1
            && $mail->counts['total_students'] === 1;
    });
});

it('never sends twice for the same day, even if run again', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.daily_report_recipients', ['ops@gunness.test']);

    ($this->run)();
    ($this->run)();

    Mail::assertSentCount(1);
});
