<?php

namespace App\Filament\RelationManagers;

use App\Models\Document;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
            // "via the Storage facade only" (migration comment) -- private
            // 'local' disk, per config/tenancy.php's own filesystem note.
            FileUpload::make('file_path')
                ->disk('local')
                ->directory('documents')
                ->required(fn (?Document $record): bool => $record === null)
                ->columnSpanFull(),
            TextInput::make('category')->maxLength(255),
            Select::make('status')
                ->options(['active' => 'Active', 'expired' => 'Expired', 'draft' => 'Draft'])
                ->default('active')
                ->required(),
            Toggle::make('is_template')->default(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('category')->placeholder('—'),
                TextColumn::make('status')->badge(),
                IconColumn::make('is_template')->boolean(),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();
                        if (isset($data['file_path']) && is_string($data['file_path'])) {
                            $data['file_mime_type'] = Storage::disk('local')->mimeType($data['file_path']) ?: null;
                        }

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (isset($data['file_path']) && is_string($data['file_path'])) {
                            $data['file_mime_type'] = Storage::disk('local')->mimeType($data['file_path']) ?: null;
                        }

                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
