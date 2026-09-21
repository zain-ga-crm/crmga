<?php

use App\Filament\Pages\CompanySettings;
use App\Models\Metadata\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.7 (settings half): a thin form over the Settings store Z-4.1 already
// built, plus Module::$enabled -- SettingsTest.php and RuntimeMailConfiguratorTest.php
// already own the store/mailer mechanics themselves.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
    app(Settings::class)->flush();
});

it('denies the page to a non-admin user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(CompanySettings::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->get(CompanySettings::getUrl())->assertSuccessful();
});

it('saves company, branding and mail settings through the Settings store', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CompanySettings::class)
        ->set('data.company_name', 'Gunness & Associates')
        ->set('data.branding_primary_color', '#112233')
        ->set('data.mail_host', 'smtp.example.test')
        ->set('data.mail_from_address', 'no-reply@example.test')
        ->call('save');

    $settings = app(Settings::class);

    expect($settings->get('company.name'))->toBe('Gunness & Associates')
        ->and($settings->get('branding.primary_color'))->toBe('#112233')
        ->and($settings->get('mail.host'))->toBe('smtp.example.test')
        ->and($settings->get('mail.from_address'))->toBe('no-reply@example.test');
});

it('stores the mail password as a secret and records who saved it', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CompanySettings::class)
        ->set('data.mail_password', 'super-secret')
        ->call('save');

    $stored = Setting::query()->where('key', 'mail.password')->firstOrFail();

    expect(app(Settings::class)->get('mail.password'))->toBe('super-secret')
        ->and($stored->is_secret)->toBeTrue()
        ->and($stored->updated_by)->toBe($admin->id);
});

it('leaves the existing mail password unchanged when the field is left blank', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    app(Settings::class)->set('mail.password', 'original-secret', secret: true);

    $this->actingAs($admin);

    Livewire::test(CompanySettings::class)
        ->set('data.mail_host', 'smtp.example.test')
        ->call('save');

    expect(app(Settings::class)->get('mail.password'))->toBe('original-secret');
});

it('toggles a module\'s enabled flag, which BuildsResourceFromMetadata::canAccess() then reads', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CompanySettings::class)
        ->set('data.modules.leads', false)
        ->call('save');

    expect(Module::query()->where('key', 'leads')->value('enabled'))->toBeFalse();
});
