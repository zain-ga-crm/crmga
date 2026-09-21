<?php

namespace App\Filament\Resources\NewsletterSubscriberResource\Pages;

use App\Filament\Resources\NewsletterSubscriberResource;
use App\Support\Filament\ConfirmationDialogs;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsletterSubscriber extends EditRecord
{
    protected static string $resource = NewsletterSubscriberResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $dialog = ConfirmationDialogs::destructive('this subscriber');

        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading($dialog['heading'])
                ->modalDescription($dialog['description'])
                ->modalIcon($dialog['icon']),
        ];
    }
}
