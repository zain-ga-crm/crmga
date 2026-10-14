<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="[\Filament\Actions\Action::make('create')->label(__('Create company'))->submit('create')]"
        />
    </form>
</x-filament-panels::page>
