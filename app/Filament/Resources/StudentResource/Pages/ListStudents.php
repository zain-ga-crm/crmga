<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Concerns\HasSavedViews;
use App\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudents extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = StudentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...StudentResource::exportHeaderActions(),
            ...$this->savedViewsActions(),
        ];
    }

    protected function savedViewModuleKey(): string
    {
        return StudentResource::moduleKey();
    }
}
