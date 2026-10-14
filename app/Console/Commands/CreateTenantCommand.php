<?php

namespace App\Console\Commands;

use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 6). CLI wrapper over
 * TenantProvisioner -- see that class for what actually happens.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {domain : The hostname this tenant will be reachable on}
        {--admin-name= : First System Administrator'."'".'s name}
        {--admin-email= : First System Administrator'."'".'s email}
        {--admin-password= : First System Administrator'."'".'s password (generated and printed if omitted)}';

    protected $description = 'Provision a new tenant: database, migrations, starter roles, first admin (BACKEND_BRIEF Z-8.4)';

    public function handle(TenantProvisioner $provisioner): int
    {
        $domain = $this->argument('domain');

        $adminName = $this->option('admin-name') ?: $this->ask('Administrator name');
        $adminEmail = $this->option('admin-email') ?: $this->ask('Administrator email');

        $generatedPassword = null;
        $adminPassword = $this->option('admin-password');
        if (! $adminPassword) {
            $adminPassword = $generatedPassword = Str::password(20);
        }

        if (! is_string($adminName) || $adminName === '' || ! is_string($adminEmail) || $adminEmail === '') {
            $this->error('Administrator name and email are required.');

            return self::FAILURE;
        }

        $tenant = $provisioner->provision((string) $domain, $adminName, $adminEmail, $adminPassword);

        $this->info("Tenant {$tenant->id} created, reachable at {$domain}.");

        if ($generatedPassword) {
            $this->warn("Generated administrator password (shown once): {$generatedPassword}");
        }

        return self::SUCCESS;
    }
}
