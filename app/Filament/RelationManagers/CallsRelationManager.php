<?php

namespace App\Filament\RelationManagers;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * S-4.3 (relation-manager half): Call's own docblock says it's "created
 * manually or by an external system via the API when calling happens
 * outside the CRM" -- manual creation IS the intended in-app path (unlike
 * Email, which only ever arrives via IMAP intake -- see
 * EmailsRelationManager, read-only for that reason). No telephony/dialer
 * integration here or anywhere in the app (BACKEND_BRIEF rule 12).
 */
class CallsRelationManager extends RelationManager
{
    protected static string $relationship = 'calls';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('direction')
                ->options(['inbound' => 'Inbound', 'outbound' => 'Outbound'])
                ->required(),
            DateTimePicker::make('date_start')->required()->default(now()),
            TextInput::make('duration_minutes')->numeric()->minValue(0),
            TextInput::make('outcome')->maxLength(255),
            Textarea::make('summary')->columnSpanFull(),
            TextInput::make('recording_url')->url()->maxLength(255)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('outcome')
            ->columns([
                TextColumn::make('direction')->badge(),
                TextColumn::make('date_start')->label('When')->dateTime()->sortable(),
                TextColumn::make('duration_minutes')->label('Minutes')->placeholder('—'),
                TextColumn::make('outcome')->placeholder('—'),
            ])
            ->defaultSort('date_start', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
