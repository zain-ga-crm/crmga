<?php

namespace App\Filament\Platform\Resources\TenantResource\Pages;

use App\Filament\Platform\Resources\TenantResource;
use App\Support\Tenancy\TenantProvisioner;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;

/**
 * S-8.2: the create-company wizard, over TenantProvisioner (shared with
 * `tenant:create` -- see that class). A custom page, not
 * Filament\Resources\Pages\CreateRecord: provisioning a tenant is a real
 * database-creation-and-seeding operation with its own result (a generated
 * password, shown once), not a plain Eloquent insert CreateRecord assumes.
 *
 * Two pieces of TASK_BREAKDOWN's S-8.2 wording are deliberately not here:
 * "modules enabled" (every module already ships enabled by default via
 * TenantProvisioner's metadata seeding step -- a selective toggle at
 * creation time would just duplicate what Company Settings' own "Enabled
 * modules" section already lets an administrator do immediately after, with
 * no loss of capability) and "the default Studio governance mode" (no
 * Studio governance backend exists yet -- Studio mode/limits/change-request
 * approval -- see S-8.3's own notes; adding an unenforced toggle here would
 * imply a capability that doesn't exist).
 *
 * @property Form $form
 */
class CreateTenant extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = TenantResource::class;

    protected static string $view = 'filament.platform.pages.create-tenant';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Create company';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Step::make('Company')
                        ->schema([
                            TextInput::make('domain')
                                ->label('Domain')
                                ->required()
                                ->helperText('The hostname this company will be reachable on, e.g. acme.crmga.local.'),
                        ]),
                    Step::make('Administrator')
                        ->schema([
                            TextInput::make('admin_name')->label('Name')->required(),
                            TextInput::make('admin_email')->label('Email')->email()->required(),
                            TextInput::make('admin_password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->helperText('Leave blank to generate one -- shown once after creation.'),
                        ]),
                ]),
            ])
            ->statePath('data');
    }

    public function create(TenantProvisioner $provisioner): void
    {
        $state = $this->form->getState();

        $domain = is_string($state['domain'] ?? null) ? $state['domain'] : '';
        $adminName = is_string($state['admin_name'] ?? null) ? $state['admin_name'] : '';
        $adminEmail = is_string($state['admin_email'] ?? null) ? $state['admin_email'] : '';

        $generatedPassword = null;
        $adminPassword = $state['admin_password'] ?? null;
        if (! is_string($adminPassword) || $adminPassword === '') {
            $adminPassword = $generatedPassword = Str::password(20);
        }

        $tenant = $provisioner->provision($domain, $adminName, $adminEmail, $adminPassword);

        if ($generatedPassword !== null) {
            Notification::make()
                ->title('Company created')
                ->body("Generated administrator password (shown once, copy it now):\n{$generatedPassword}")
                ->success()
                ->persistent()
                ->send();
        } else {
            Notification::make()->title('Company created')->success()->send();
        }

        $this->redirect(TenantResource::getUrl('view', ['record' => $tenant]));
    }
}
