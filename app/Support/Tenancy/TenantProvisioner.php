<?php

namespace App\Support\Tenancy;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetadataFixtureSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Z-8.4: provisions a brand-new tenant -- database creation and
 * `database/migrations/tenant` run automatically via the TenantCreated event
 * pipeline (App\Providers\TenancyServiceProvider, Z-8.1); this class's own
 * job is everything after that: the domain, the starter roles, and the first
 * System Administrator, so the tenant is actually usable, not just present.
 *
 * Shared by CreateTenantCommand (CLI) and the S-8.2 create-company wizard
 * (platform panel) -- provisioning logic that creates a real tenant database
 * and a real admin login belongs in exactly one place, not duplicated
 * between a console command and a web form.
 */
final class TenantProvisioner
{
    public function provision(string $domain, string $adminName, string $adminEmail, string $adminPassword): Tenant
    {
        // Tenant::create() fires TenantCreated, which -- per Z-8.1's
        // TenancyServiceProvider -- runs CreateDatabase then MigrateDatabase
        // synchronously (shouldBeQueued(false)) before this returns.
        $tenant = Tenant::create();
        $tenant->createDomain($domain);

        tenancy()->initialize($tenant);

        try {
            Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
            Artisan::call('db:seed', ['--class' => SettingsCatalogueSeeder::class, '--force' => true]);
            // Without this, every BuildsResourceFromMetadata resource (Leads,
            // Companies, ...) renders completely empty for a new tenant -- no
            // module/field/layout rows exist until this runs (the same gap
            // this session found and fixed for the 'assessments' module
            // specifically; this closes it for every new tenant going forward).
            Artisan::call('db:seed', ['--class' => MetadataFixtureSeeder::class, '--force' => true]);

            $admin = User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $adminPassword,
                'is_admin' => true,
            ]);

            $adminRole = Role::query()->where('name', 'Administrator')->first();
            if ($adminRole) {
                $admin->roles()->attach($adminRole);
            }
        } finally {
            tenancy()->end();
        }

        return $tenant;
    }
}
