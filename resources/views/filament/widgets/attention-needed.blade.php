<x-filament-widgets::widget>
    <x-filament::section heading="Attention needed">
        @php($records = $this->records())

        @if (empty($records))
            <p class="fi-in-placeholder text-sm text-gray-500 dark:text-gray-400">Nothing overdue.</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($records as $record)
                    <li class="flex items-center justify-between gap-4 py-2">
                        <a href="{{ $record['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ $record['full_name'] }}
                        </a>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::parse($record['due_at'])->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
