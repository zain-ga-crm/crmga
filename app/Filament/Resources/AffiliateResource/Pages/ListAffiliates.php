<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Filament\Resources\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAffiliates extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = AffiliateResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...AffiliateResource::exportHeaderActions(),
            ...$this->savedViewsActions(),
        ];
    }

    protected function savedViewModuleKey(): string
    {
        return AffiliateResource::moduleKey();
    }
}
