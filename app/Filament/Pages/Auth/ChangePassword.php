<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * S-1.2's forced first-login step (crmga_Frontend_Design_Spec.docx §7): shown
 * instead of the dashboard whenever User::mustChangePassword() is true --
 * see ForcePasswordChange, the middleware that actually redirects here. Not
 * registered in the sidebar navigation; reached only via that redirect.
 *
 * The spec's "optional two-factor enrolment step" that follows this in the
 * first-sign-in stepper is a separate page (Security, reachable afterwards)
 * rather than a second step of the same wizard -- simpler to get right than
 * a multi-step Livewire form, and 2FA enrolment isn't only relevant at
 * first login (a user may want to enable it later too).
 *
 * @property Form $form
 */
class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.auth.change-password';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Set a new password';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(PasswordRule::default())
                    ->autocomplete('new-password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('new-password')
                    ->same('password')
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $password = $state['password'] ?? null;
        if (! is_string($password) || $password === '') {
            return;
        }

        /** @var User $user */
        $user = Filament::auth()->user();
        $user->forceFill(['password' => Hash::make($password)])->save();
        $user->markPasswordChanged();

        Notification::make()
            ->title('Password updated')
            ->success()
            ->send();

        $this->redirect(Filament::getUrl());
    }
}
