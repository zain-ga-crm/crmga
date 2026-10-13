<?php

use App\Filament\Pages\IntegrationHub;
use App\Models\Lead;
use App\Models\User;
use App\Support\Ingest\FieldMapRepository;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-5.2: integration hub cards/status + WordPress/Meta configuration + the
// shared field-mapping editor + test-payload runner. FieldMapRepository and
// the live /api/v1/ingest/* endpoints already existed in full (Z-5.6) --
// this is purely the missing interface over them.
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('denies the page to a non-admin user', function () {
    promotePrimaryTenant();
    $this->actingAs(User::factory()->create());

    $this->get(IntegrationHub::getUrl())->assertForbidden();
});

it('reports not configured until a credential is saved, then configured', function () {
    $component = Livewire::test(IntegrationHub::class);
    expect($component->instance()->wordpressConfigured())->toBeFalse();

    $component->fillForm(['api_key' => 'secret-key-123'], 'wordpressForm')->call('saveWordpress');

    expect($component->instance()->wordpressConfigured())->toBeTrue()
        ->and(app(Settings::class)->get('ingest.wordpress.api_key'))->toBe('secret-key-123');
});

it('leaves an existing credential unchanged when the field is left blank on save', function () {
    app(Settings::class)->set('ingest.wordpress.api_key', 'original-key', secret: true);

    Livewire::test(IntegrationHub::class)
        ->fillForm(['api_key' => ''], 'wordpressForm')
        ->call('saveWordpress');

    expect(app(Settings::class)->get('ingest.wordpress.api_key'))->toBe('original-key');
});

it('saves meta\'s verify token (not secret) and app secret (secret) separately', function () {
    Livewire::test(IntegrationHub::class)
        ->fillForm(['verify_token' => 'my-verify-token', 'app_secret' => 'my-app-secret'], 'metaForm')
        ->call('saveMeta');

    expect(app(Settings::class)->get('ingest.meta.verify_token'))->toBe('my-verify-token')
        ->and(app(Settings::class)->get('ingest.meta.app_secret'))->toBe('my-app-secret')
        ->and(Livewire::test(IntegrationHub::class)->instance()->metaConfigured())->toBeTrue();
});

it('saves a field mapping through the repeater and FieldMapRepository reads it back', function () {
    Livewire::test(IntegrationHub::class)
        ->fillForm([
            'api_key' => 'k',
            'field_map' => [
                ['source_field' => 'your-email', 'target_field' => 'primary_email', 'transform' => 'lower', 'default' => null],
            ],
        ], 'wordpressForm')
        ->call('saveWordpress');

    $mappings = app(FieldMapRepository::class)->forSource('wordpress');
    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]->sourceField)->toBe('your-email')
        ->and($mappings[0]->targetField)->toBe('primary_email')
        ->and($mappings[0]->transform)->toBe('lower');
});

it('previews field mapping and transforms against a pasted payload without creating a record', function () {
    Livewire::test(IntegrationHub::class)
        ->fillForm([
            'api_key' => 'k',
            'field_map' => [
                ['source_field' => 'email', 'target_field' => 'primary_email', 'transform' => 'lower', 'default' => null],
            ],
        ], 'wordpressForm')
        ->call('saveWordpress');

    $component = Livewire::test(IntegrationHub::class)
        ->fillForm(['source' => 'wordpress', 'payload' => '{"email": "AMINA@EXAMPLE.COM"}'], 'testRunnerForm')
        ->call('runTest');

    expect($component->get('testRunnerResult'))->toBe(['primary_email' => 'amina@example.com']);

    expect(Lead::query()->count())->toBe(0);
});

it('rejects invalid JSON in the test-payload runner without throwing', function () {
    Livewire::test(IntegrationHub::class)
        ->fillForm(['source' => 'wordpress', 'payload' => 'not json'], 'testRunnerForm')
        ->call('runTest')
        ->assertNotified();
});
