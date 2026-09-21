<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\Concerns\HasActivityTimelineFooter;
use App\Filament\Resources\LeadResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    use HasActivityTimelineFooter;

    protected static string $resource = LeadResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
