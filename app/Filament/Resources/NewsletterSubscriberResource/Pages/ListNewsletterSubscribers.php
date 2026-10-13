<?php

namespace App\Filament\Resources\NewsletterSubscriberResource\Pages;

use App\Filament\Resources\Concerns\HasSavedViews;
use App\Filament\Resources\NewsletterSubscriberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsletterSubscribers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = NewsletterSubscriberResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...NewsletterSubscriberResource::exportHeaderActions(),
            ...$this->savedViewsActions(),
        ];
    }

    protected function savedViewModuleKey(): string
    {
        return NewsletterSubscriberResource::moduleKey();
    }
}
