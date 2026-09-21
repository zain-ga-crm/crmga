<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Pages\RolePermissionMatrix;
use App\Filament\Resources\RoleResource;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Role $record */
        $record = $this->getRecord();

        return [
            Action::make('permissions')
                ->label('Edit permissions')
                ->icon('heroicon-o-table-cells')
                ->url(RolePermissionMatrix::getUrl(['role' => $record->id])),
            DeleteAction::make()->visible(! $record->is_system),
        ];
    }
}
