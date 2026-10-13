<?php

namespace App\Filament\Resources\AssessmentResource\Pages;

use App\Filament\Resources\AssessmentResource;
use App\Filament\Resources\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessments extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = AssessmentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...AssessmentResource::exportHeaderActions(),
            ...$this->savedViewsActions(),
        ];
    }

    protected function savedViewModuleKey(): string
    {
        return AssessmentResource::moduleKey();
    }
}
