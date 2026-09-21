<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Widgets\ActivityTimelineWidget;

/**
 * S-4.3: puts the unified activity timeline on a ViewRecord page's footer.
 * Filament's InteractsWithRecord::getWidgetData() already passes 'record'
 * to every header/footer widget on the page automatically -- nothing here
 * needs to pass it along by hand.
 */
trait HasActivityTimelineFooter
{
    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            ActivityTimelineWidget::class,
        ];
    }
}
