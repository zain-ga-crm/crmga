<?php

use App\Jobs\SendDailyLeadCountReportJob;
use App\Mail\DailyCountReportMail;
use App\Models\Lead;
use App\Support\NotificationDedupGuard;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTruncation::class);

beforeEach(function () {
    // Settings caches its compiled map under a fixed key regardless of which
    // test set it -- DatabaseTruncation resets the table, not the cache
    // store, and this app intentionally keeps one booted app across tests
    // (Z-8.3), so a value another test file wrote survives here otherwise.
    app(Settings::class)->flush();

    $this->run = fn () => app(SendDailyLeadCountReportJob::class)
        ->handle(app(Settings::class), app(NotificationDedupGuard::class));
});

it('is a no-op until report recipients are configured', function () {
    Mail::fake();

    ($this->run)();

    Mail::assertNothingSent();
});

it('emails the configured recipients with today\'s lead counts only', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.daily_report_recipients', ['ops@gunness.test']);

    Lead::factory()->create(['hot_lead' => true]);
    Lead::factory()->create(['stage' => 'lost']);

    ($this->run)();

    Mail::assertSent(DailyCountReportMail::class, function (DailyCountReportMail $mail) {
        return $mail->title === 'Daily lead count report'
            && $mail->hasTo('ops@gunness.test')
            && $mail->counts['total_leads'] === 2
            && $mail->counts['hot_leads'] === 1
            && $mail->counts['open_leads'] === 1
            && ! array_key_exists('total_students', $mail->counts);
    });
});

it('never sends twice for the same day, even if run again', function () {
    Mail::fake();
    app(Settings::class)->set('notifications.daily_report_recipients', ['ops@gunness.test']);

    ($this->run)();
    ($this->run)();

    Mail::assertSentCount(1);
});
