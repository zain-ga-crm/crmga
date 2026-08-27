<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Not itself ShouldQueue — it is always built and sent from inside
 * SendDailyLeadCountReportJob/SendDailyStudentCountReportJob, which already
 * run on the queue. §11 requires these as two separate notifications, not one
 * merged report -- $title is what tells them apart; $counts/$date/the view
 * are shared since both are just "a set of named counts as of today."
 */
final class DailyCountReportMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $title,
        public readonly array $counts,
        public readonly Carbon $date,
    ) {}

    public function build(): self
    {
        return $this->subject($this->title.' — '.$this->date->toDateString())
            ->view('emails.daily-count-report');
    }
}
