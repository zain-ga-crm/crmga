<x-filament-panels::page>
    <x-filament::section>
        <div class="max-w-sm">
            <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="dropdown-editor-list">
                {{ __('List') }}
            </label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="dropdown-editor-list" wire:model.live="listKey">
                    @foreach ($this->listOptions() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
