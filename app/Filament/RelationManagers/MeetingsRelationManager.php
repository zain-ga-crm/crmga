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
 * S-4.3 (relation-manager half): full CRUD -- meetings are logged directly
 * by CRM users, unlike Call/Email which are mostly written by external
 * systems (see CallsRelationManager/EmailsRelationManager for that
 * distinction).
 */
class MeetingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meetings';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('location')->maxLength(255),
            Select::make('status')
                ->options(['planned' => 'Planned', 'held' => 'Held', 'not_held' => 'Not held'])
                ->default('planned')
                ->required(),
            DateTimePicker::make('date_start')->required()->default(now()),
            DateTimePicker::make('date_end'),
            TextInput::make('duration_minutes')->numeric()->minValue(0),
            Select::make('assigned_user_id')->relationship('assignedUser', 'name')->searchable()->preload(),
            Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('date_start')->label('When')->dateTime()->sortable(),
                TextColumn::make('location')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('assignedUser.name')->label('Owner')->placeholder('—'),
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
