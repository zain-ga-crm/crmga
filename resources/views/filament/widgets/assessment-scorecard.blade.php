<x-filament-widgets::widget>
    <x-filament::section heading="Scorecard">
        @php($summary = $this->summary())
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            @foreach ($summary as $item)
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $item['label'] }}</dt>
                    <dd class="text-sm font-medium text-gray-950 dark:text-white">{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section heading="Factor breakdown">
        @php($factors = $this->factors())

        @if (empty($factors))
            <p class="fi-in-placeholder text-sm text-gray-500 dark:text-gray-400">No factor breakdown recorded.</p>
        @else
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($factors as $factor)
                    <div class="flex items-baseline justify-between gap-2 border-b border-gray-100 pb-1 dark:border-white/5">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $factor['label'] }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white">{{ $factor['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
