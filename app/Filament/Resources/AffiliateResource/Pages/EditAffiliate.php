<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Support\Filament\ConfirmationDialogs;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $dialog = ConfirmationDialogs::destructive('this affiliate');

        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading($dialog['heading'])
                ->modalDescription($dialog['description'])
                ->modalIcon($dialog['icon']),
        ];
    }
}
