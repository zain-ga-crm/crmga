<?php

namespace App\Jobs;

use App\Mail\DailyCountReportMail;
use App\Models\Student;
use App\Support\NotificationDedupGuard;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily student count notification (§11: `12 10 * * *` company time zone —
 * the lead and student counts are two separate scheduled notifications, not
 * one merged report; see SendDailyLeadCountReportJob for the other half).
 * Recipients come from the settings store (never .env — tenancy-ready rule
 * 3); a no-op until that list is set. Runs with no authenticated user, so
 * the ACL-scoped Student model must bypass AppliesRecordAccess — this is a
 * company-wide digest, not scoped to any one owner. Guarded by
 * NotificationDedupGuard so it can never send twice for one company-local
 * day, computed in the company time zone rather than the server's.
 */
final class SendDailyStudentCountReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Settings $settings, NotificationDedupGuard $guard): void
    {
        $recipients = $settings->get('notifications.daily_report_recipients', []);
        if (! is_array($recipients) || $recipients === []) {
            return;
        }

        $timezoneRaw = $settings->get('system.timezone', config('app.timezone'));
        $timezone = is_string($timezoneRaw) ? $timezoneRaw : 'UTC';
        $today = Carbon::now($timezone)->startOfDay();

        if (! $guard->claim('daily_student_count_report:'.$today->toDateString())) {
            return;
        }

        $counts = [
            'new_students_today' => Student::withoutGlobalScopes()->whereDate('created_at', $today)->count(),
            'total_students' => Student::withoutGlobalScopes()->count(),
        ];

        Mail::to($recipients)->send(new DailyCountReportMail('Daily student count report', $counts, $today));
    }
}
