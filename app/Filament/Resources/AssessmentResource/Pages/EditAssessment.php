<?php

namespace App\Filament\Resources\AssessmentResource\Pages;

use App\Filament\Resources\AssessmentResource;
use App\Support\Filament\ConfirmationDialogs;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAssessment extends EditRecord
{
    protected static string $resource = AssessmentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $dialog = ConfirmationDialogs::destructive('this assessment');

        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading($dialog['heading'])
                ->modalDescription($dialog['description'])
                ->modalIcon($dialog['icon']),
        ];
    }
}
