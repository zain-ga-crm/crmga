{{--
    S-1.4: reusable inline loading indicator for custom Livewire pages/sections
    (e.g. app/Filament/Pages/Security.php's enroll/confirm/disable actions) --
    Filament's own tables and form submit buttons already show a built-in
    loading state on their own, so this is only for a custom section that
    isn't one of those.

    Usage: <x-loading-state /> or <x-loading-state label="Saving…" wire:target="save" />
--}}
@props(['label' => 'Loading…'])

<span
    {{ $attributes->merge(['class' => 'hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400']) }}
    wire:loading.class.remove="hidden"
    wire:loading.class="inline-flex"
>
    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
    </svg>
    <span>{{ $label }}</span>
</span>
