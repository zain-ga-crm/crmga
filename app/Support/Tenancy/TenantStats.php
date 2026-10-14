<?php

namespace App\Support\Tenancy;

use App\Models\Affiliate;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * S-8.1: per-company stats for the platform panel's companies list/detail
 * ("subdomain, users, records, database size, status" -- TASK_BREAKDOWN).
 * User/record counts live inside each tenant's own database, so computing
 * them means switching into that tenant the same way TenantIsolationTest
 * and TenantProvisioner already do: tenancy()->initialize() -> read ->
 * tenancy()->end() in a finally block, every time, so a failure here never
 * leaves a later, unrelated request running against the wrong database.
 */
final class TenantStats
{
    /**
     * @return array{users: int, records: int, database_size_mb: float}
     */
    public function forTenant(Tenant $tenant): array
    {
        tenancy()->initialize($tenant);

        try {
            $records = Lead::withoutGlobalScopes()->count()
                + Company::withoutGlobalScopes()->count()
                + Student::withoutGlobalScopes()->count()
                + Client::withoutGlobalScopes()->count()
                + Affiliate::withoutGlobalScopes()->count()
                + NewsletterSubscriber::withoutGlobalScopes()->count();

            return [
                'users' => User::query()->count(),
                'records' => $records,
                'database_size_mb' => $this->databaseSizeMb(),
            ];
        } finally {
            tenancy()->end();
        }
    }

    private function databaseSizeMb(): float
    {
        $row = DB::selectOne(
            'select round(sum(data_length + index_length) / 1024 / 1024, 2) as size_mb '.
            'from information_schema.tables where table_schema = database()',
        );

        $size = is_object($row) ? ($row->size_mb ?? null) : null;

        return is_numeric($size) ? (float) $size : 0.0;
    }
}
