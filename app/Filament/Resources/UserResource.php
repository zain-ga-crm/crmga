<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * S-4.6 (user management half): user CRUD plus role assignment. Not
 * metadata-driven -- User's shape is fixed application config, same
 * reasoning as RoleResource. Access itself (what a signed-in user can see
 * and do) is entirely Acl::effective() reading the roles assigned here;
 * this resource only manages the assignment, never re-implements it.
 *
 * A user can't delete their own account, individually or via bulk delete --
 * losing the one session you're acting through has no recovery path from
 * inside the panel itself.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('username')->maxLength(255)->unique(ignoreRecord: true),
                    TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required(fn (?User $record): bool => $record === null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (?User $record): ?string => $record === null ? null : 'Leave blank to keep the current password.'),
                    Select::make('status')
                        ->options(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'])
                        ->default('active')
                        ->required(),
                    Toggle::make('is_admin')->label('Administrator')->default(false),
                ]),
            Section::make('Organization')
                ->columns(2)
                ->schema([
                    Select::make('reports_to_id')
                        ->label('Reports to')
                        ->relationship('manager', 'name', fn (Builder $query, ?User $record): Builder => $record !== null ? $query->whereKeyNot($record->getKey()) : $query)
                        ->searchable()
                        ->preload(),
                    Select::make('roles')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    TextInput::make('locale')->default('en')->required(),
                    TextInput::make('timezone')->default('UTC')->required()->helperText('e.g. America/Toronto'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'active' => 'success',
                    'inactive' => 'gray',
                    'suspended' => 'danger',
                    default => 'gray',
                }),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
                TextColumn::make('roles.name')->label('Roles')->badge()->limitList(3),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (User $record): bool => $record->id !== Auth::id()),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->action(function (Collection $records): void {
                        $records->reject(fn (Model $record): bool => $record->getKey() === Auth::id())->each->delete();
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
