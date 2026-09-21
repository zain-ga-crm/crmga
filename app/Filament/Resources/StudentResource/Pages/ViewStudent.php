<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Concerns\HasActivityTimelineFooter;
use App\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStudent extends ViewRecord
{
    use HasActivityTimelineFooter;

    protected static string $resource = StudentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
