<?php

namespace App\Filament\Widgets;

use App\Models\Affiliate;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use App\Support\Filament\ActivityFeedFormatter;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;

/**
 * S-4.3: the unified chronological feed on a record's View page -- footer
 * widget on ViewRecord pages (HasActivityTimelineFooter), which Filament
 * auto-hydrates $record from via InteractsWithRecord::getWidgetData().
 *
 * Merges HasActivities::activityFeed() (meetings/notes/documents/calls/
 * tasks/emails) with the record's own laravel-auditing rows ("field
 * changes"). SMS is deliberately absent -- BACKEND_BRIEF rule 12 forbids
 * an SMS module/table entirely, even though S-4.3's own task description
 * lists it as a feed type.
 *
 * $record is typed to the exact six HasActivities-using modules (the same
 * set ContactableModuleRegistry lists) rather than a trait-intersection
 * type, which PHPStan/Larastan can't resolve for a plain property.
 *
 * v1: newest 50, no further pagination -- matches every other dashboard
 * widget's fixed-limit approach (Z-4.3's callsToMake()/attentionNeeded()
 * both default to a flat limit too).
 */
class ActivityTimelineWidget extends Widget
{
    protected static string $view = 'filament.widgets.activity-timeline';

    // Record-scoped, only ever used via HasActivityTimelineFooter's
    // getFooterWidgets() -- without this, discoverWidgets() also registers
    // it panel-wide on the general Dashboard, where it has no $record and
    // permanently renders "No activity yet."
    protected static bool $isDiscovered = false;

    public Lead|Company|Student|Client|Affiliate|NewsletterSubscriber|null $record = null;

    private const LIMIT = 50;

    /**
     * @return array<int, array{type: string, icon: string, title: string, summary: string|null, who: string, when: Carbon}>
     */
    public function records(): array
    {
        if ($this->record === null) {
            return [];
        }

        $record = $this->record;

        $audits = Audit::query()
            ->where('auditable_type', $record::class)
            ->where('auditable_id', $record->getKey())
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return $record->activityFeed()
            ->concat($audits)
            ->map(fn ($item) => ActivityFeedFormatter::describe($item))
            ->sortByDesc('when')
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
