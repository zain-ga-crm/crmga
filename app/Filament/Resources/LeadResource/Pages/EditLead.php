<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use App\Support\Filament\ConfirmationDialogs;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $dialog = ConfirmationDialogs::destructive('this lead');

        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading($dialog['heading'])
                ->modalDescription($dialog['description'])
                ->modalIcon($dialog['icon']),
        ];
    }
}
