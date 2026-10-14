<?php

use App\Filament\Pages\ApiDocs;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Yaml\Yaml;

// S-5.3: OpenAPI-driven documentation page. The spec is a static,
// hand-written file (public/api-docs/openapi.yaml) implementing
// docs/contracts/api-contract.md -- coverage here is about the page being
// reachable and the spec being valid/served, not re-deriving the API's
// own behaviour (already covered elsewhere).
uses(DatabaseTruncation::class);

it('denies the page to a non-admin user', function () {
    promotePrimaryTenant();
    $this->actingAs(User::factory()->create());

    $this->get(ApiDocs::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    promotePrimaryTenant();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(ApiDocs::getUrl())->assertSuccessful();
});

it('ships a valid OpenAPI spec describing the documented endpoints', function () {
    // public/api-docs/openapi.yaml is a plain static file under the public
    // document root -- the real webserver (Apache/nginx) serves it directly
    // without going through Laravel's router at all, same as public/build's
    // compiled assets, so there is no route to exercise here via $this->get().
    $path = public_path('api-docs/openapi.yaml');
    expect(file_exists($path))->toBeTrue();

    $spec = Yaml::parseFile($path);

    expect($spec['openapi'])->toBe('3.0.3')
        ->and($spec['paths'])->toHaveKeys(['/{module}', '/{module}/{id}', '/ingest/wordpress', '/ingest/meta', '/ingest/{source}']);
});
