<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Z-7.1 (security review): baseline response headers, global (appended in
 * bootstrap/app.php, not scoped to the admin panel or the API alone, so
 * every response carries them). Deliberately NOT including a
 * Content-Security-Policy here -- Filament's own Livewire/Alpine asset
 * pipeline needs its script/style sources worked out and live-verified in
 * the browser before a CSP can ship without silently breaking the panel;
 * that's a scoped follow-up, not an oversight.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
