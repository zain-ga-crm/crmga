<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Not itself ShouldQueue — it is always built and sent from inside
 * SendFailedJobAlertJob, which already runs on the queue.
 */
final class FailedJobAlertMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly int $count,
        public readonly int $threshold,
    ) {}

    public function build(): self
    {
        return $this->subject("Failed job alert — {$this->count} failure(s) in the last hour")
            ->view('emails.failed-job-alert');
    }
}
