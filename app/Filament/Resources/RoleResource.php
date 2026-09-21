<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * S-4.6: role CRUD. Unlike Lead/Company/etc., Role isn't metadata-driven --
 * it's a fixed, hand-built shape (name/description/is_system), so this is a
 * plain Filament resource, not BuildsResourceFromMetadata. Creating a role
 * here fires Role::created (AppServiceProvider), which auto-backfills a
 * 'none' row per module via Acl::registerRole() -- nothing here needs to
 * seed the permission matrix itself.
 *
 * The one system role (Administrator, per RoleSeeder) is protected from
 * renaming and deletion, same pattern DropdownEditor uses for system
 * option lists.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Role $record): bool => $record?->is_system === true)
                ->maxLength(255),
            Textarea::make('description')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->limit(60)->placeholder('—'),
                IconColumn::make('is_system')->label('System')->boolean(),
                TextColumn::make('users_count')->label('Users')->counts('users'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Role $record): bool => ! $record->is_system),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->action(function (Collection $records): void {
                        // System roles never fall out of a bulk selection silently --
                        // everything else in the batch still deletes normally.
                        $records->reject(fn (Model $record): bool => $record instanceof Role && $record->is_system)->each->delete();
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
