<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Filament\Resources\Concerns\HasActivityTimelineFooter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAffiliate extends ViewRecord
{
    use HasActivityTimelineFooter;

    protected static string $resource = AffiliateResource::class;

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
