<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-700 dark:text-gray-300">Company</label>
        <select
            wire:model.live="tenantId"
            class="fi-select-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
        >
            @foreach ($this->tenantOptions() as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="mt-6 space-y-4">
        @php($requests = $this->pendingRequests())

        @forelse ($requests as $request)
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $request['kind'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $request['target_module'] }}@if ($request['target_field']) &middot; {{ $request['target_field'] }} @endif
                            &middot; {{ $request['created_at'] }}
                        </p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                            @foreach ($request['summary'] as $label => $value)
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="text-gray-950 dark:text-white">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <x-filament::button color="success" size="sm" wire:click="approve('{{ $request['id'] }}')">
                            Approve
                        </x-filament::button>
                        <x-filament::button color="danger" size="sm" wire:click="reject('{{ $request['id'] }}')">
                            Reject
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @empty
            <div class="fi-section rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
                No pending Studio requests for this company.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
