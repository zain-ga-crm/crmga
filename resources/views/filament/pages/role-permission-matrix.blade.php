<x-filament-panels::page>
    <x-filament::section>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="roleId">
                @foreach ($this->roleOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
