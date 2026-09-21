<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Support\Filament\ConfirmationDialogs;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $dialog = ConfirmationDialogs::destructive('this student');

        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading($dialog['heading'])
                ->modalDescription($dialog['description'])
                ->modalIcon($dialog['icon']),
        ];
    }
}
