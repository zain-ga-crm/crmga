<x-filament-panels::page>
    @if (! $this->isEnrolling && ! $this->user()->hasTwoFactorEnabled())
        <x-filament::section>
            <p>{{ __('Add an extra layer of security to your account by requiring an authenticator app code at sign-in.') }}</p>

            <x-filament::button wire:click="startEnrolling" class="mt-4">
                {{ __('Enable two-factor authentication') }}
            </x-filament::button>
        </x-filament::section>
    @elseif ($this->isEnrolling)
        <x-filament::section>
            <div class="flex flex-col items-start gap-4">
                <div>{!! $this->qrCodeSvg() !!}</div>

                <p class="fi-caption">
                    {{ __('Scan this with your authenticator app, then enter the code it shows below.') }}
                </p>

                @if ($this->pendingRecoveryCodes)
                    <div>
                        <p class="fi-caption font-semibold">{{ __('Backup codes -- save these somewhere safe, they will not be shown again:') }}</p>
                        <ul class="font-mono text-sm">
                            @foreach ($this->pendingRecoveryCodes as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <form wire:submit="confirm" class="mt-4">
                {{ $this->form }}

                <x-filament-panels::form.actions
                    :actions="[
                        \Filament\Actions\Action::make('confirm')->label(__('Confirm'))->submit('confirm'),
                        \Filament\Actions\Action::make('done')->label(__('Done'))->color('gray')->action('finishEnrolling'),
                    ]"
                />
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <p>{{ __('Two-factor authentication is enabled on your account.') }}</p>

            <x-filament::button wire:click="disable" color="danger" class="mt-4">
                {{ __('Disable two-factor authentication') }}
            </x-filament::button>
        </x-filament::section>
    @endif

    <x-filament::section heading="API access">
        <p class="fi-caption">
            {{ __('Personal access tokens let scripts and tools act as you against the REST API. Create a client instead (Settings → API Clients) for a server-to-server integration that is not acting as any one person.') }}
        </p>

        @if ($this->pendingPlainTextToken)
            <div class="mt-4">
                <p class="fi-caption font-semibold">{{ __('Token -- copy it now, it will not be shown again:') }}</p>
                <p class="break-all font-mono text-sm">{{ $this->pendingPlainTextToken }}</p>

                <x-filament::button wire:click="dismissPendingToken" color="gray" class="mt-2">
                    {{ __('Done') }}
                </x-filament::button>
            </div>
        @else
            <form wire:submit="createToken" class="mt-4 max-w-lg">
                {{ $this->tokenForm }}

                <x-filament-panels::form.actions
                    :actions="[\Filament\Actions\Action::make('createToken')->label(__('Create token'))->submit('createToken')]"
                />
            </form>
        @endif

        @php($tokens = $this->tokens())

        @if ($tokens->isNotEmpty())
            <ul class="mt-6 divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $token->name }}</p>
                            <p class="fi-caption">
                                {{ $token->scopes === [] ? __('All scopes') : implode(', ', $token->scopes) }}
                                &middot; {{ __('Created') }} {{ $token->created_at?->diffForHumans() }}
                            </p>
                        </div>

                        <x-filament::button wire:click="revokeToken('{{ $token->id }}')" color="danger" size="sm">
                            {{ __('Revoke') }}
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
