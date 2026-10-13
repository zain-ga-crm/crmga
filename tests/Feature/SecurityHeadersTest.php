<?php

// Z-7.1: baseline security headers on every response, appended globally in
// bootstrap/app.php -- checked against the health-check route since it
// needs no auth/tenancy setup to reach.
it('sets baseline security headers on every response', function () {
    $this->get('/up')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

it('does not set Strict-Transport-Security over a plain HTTP request', function () {
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
});

it('sets Strict-Transport-Security when the request is secure', function () {
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
