<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\Concerns\HasSavedViews;
use App\Filament\Resources\LeadResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = LeadResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...LeadResource::exportHeaderActions(),
            ...$this->savedViewsActions(),
        ];
    }

    protected function savedViewModuleKey(): string
    {
        return LeadResource::moduleKey();
    }
}
