<x-filament-panels::page>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">

    <x-filament::section>
        <p class="fi-caption">
            {{ __('Authenticate with the Authorize button below using a personal access token from the Security page, or an OAuth client-credentials token from API Clients, then use "Try it out" on any endpoint to call the real API.') }}
        </p>
    </x-filament::section>

    <div id="swagger-ui" class="mt-6"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', function () {
            SwaggerUIBundle({
                url: '{{ asset('api-docs/openapi.yaml') }}',
                dom_id: '#swagger-ui',
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
                tryItOutEnabled: true,
            });
        });
    </script>
</x-filament-panels::page>
