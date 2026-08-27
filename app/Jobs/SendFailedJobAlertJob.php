<?php

namespace App\Jobs;

use App\Mail\FailedJobAlertMail;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * §11: "Failed-job alert: hourly, notifies administrators when failures
 * exceed a threshold." Scheduled hourly with withoutOverlapping (routes/
 * console.php) rather than guarded by NotificationDedupGuard -- unlike the
 * daily count reports, this isn't "send exactly once for a given day," it's
 * "tell someone right now if the last hour looked bad," so re-alerting on a
 * later hour that's still over threshold is the correct behaviour, not a
 * duplicate to suppress.
 */
final class SendFailedJobAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Settings $settings): void
    {
        $recipients = $settings->get('notifications.failed_job_alert_recipients', []);
        if (! is_array($recipients) || $recipients === []) {
            return;
        }

        $threshold = $this->intSetting($settings, 'notifications.failed_job_alert_threshold', 5);

        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();

        if ($count < $threshold) {
            return;
        }

        Mail::to($recipients)->send(new FailedJobAlertMail($count, $threshold));
    }

    private function intSetting(Settings $settings, string $key, int $default): int
    {
        $value = $settings->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
