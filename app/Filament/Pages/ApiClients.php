<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * S-5.1 (client half): server-to-server OAuth clients for the REST API --
 * what the 133 n8n workflows and any other external integration authenticate
 * with (client-credentials grant, Z-5.3). The backend was already fully
 * built -- Passport::$registersJsonApiRoutes in AppServiceProvider gives
 * /oauth/clients for free, and OAuthClientRevokeCommand already does the
 * exact revoke this page's action does -- there was just no interface.
 *
 * Personal access tokens (the other half of S-5.1) live on the Security
 * page instead: they belong to one user's own account, the same audience
 * as 2FA, not an admin-only settings screen.
 *
 * "Scopes" per TASK_BREAKDOWN's S-5.1 wording don't apply to a
 * client-credentials client in vanilla Passport the way they do to a
 * personal token -- a client-credentials token can request any scope
 * Passport::tokensCan() defines (ApiScopes::all()), validated at the
 * token-request step, not restricted per client. Nothing invented here to
 * work around that.
 */
class ApiClients extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static string $view = 'filament.pages.api-clients';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'API Clients';

    protected static ?string $slug = 'api-clients';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'API Clients';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Client::query()
                ->whereJsonContains('grant_types', 'client_credentials')
                ->latest())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('id')->label('Client ID')->copyable(),
                IconColumn::make('revoked')->boolean()->trueIcon('heroicon-o-x-circle')->falseIcon('heroicon-o-check-circle')->trueColor('danger')->falseColor('success'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                $this->createClientAction(),
            ])
            ->actions([
                $this->revokeAction(),
            ])
            ->emptyStateHeading('No API clients yet')
            ->emptyStateDescription('Create one for each external integration (e.g. an n8n workflow) that authenticates with the REST API.');
    }

    private function createClientAction(): Action
    {
        return Action::make('create')
            ->label('Create client')
            ->icon('heroicon-o-plus')
            ->form([
                TextInput::make('name')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $name = is_string($data['name'] ?? null) ? $data['name'] : '';
                $client = app(ClientRepository::class)->createClientCredentialsGrantClient($name);

                Notification::make()
                    ->title("Client \"{$client->name}\" created")
                    ->body("Client secret (shown once, copy it now):\n{$client->plainSecret}")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(fn (Client $record): string => $record->revoked ? 'Unrevoke' : 'Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Client $record): void {
                $record->forceFill(['revoked' => ! $record->revoked])->save();

                Notification::make()
                    ->title($record->revoked ? 'Client revoked' : 'Client unrevoked')
                    ->success()
                    ->send();
            });
    }
}
