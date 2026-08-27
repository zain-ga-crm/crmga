<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the starter roles from docs/reference/roles.php (BACKEND_BRIEF §8.5).
 *
 * The reconciliation the old NOTE here deferred to "Phase 6 ETL" is now done:
 * parsed `acl_roles` directly from the live production dump (2026-08-27).
 * Of 44 rows, 13 are soft-deleted test/duplicate roles (`deleted = 1`) and 4
 * more are throwaway test rows an admin created directly in production
 * ('Test' x2, 'Test Z', 'hamid test') -- excluding both leaves exactly the 27
 * real roles below (plus the synthetic 'Administrator', not a real acl_roles
 * row -- SuiteCRM's admin is the `users.is_admin` flag, not an ACL role).
 * Neither planning-doc figure (27 in the reference file, 29 everywhere else)
 * was quite right: 'Appointment Setter - Regular User New' was created
 * 2026-08-04 -- after every planning doc was written -- so it appears in
 * neither. 28 real roles today.
 */
class RoleSeeder extends Seeder
{
    /** @var list<string> */
    private const ROLES = [
        'Administrator',
        'Appointment Setter - Regular',
        'Appointment Setter - Regular User New',
        'Appointment Setter - Supervisor',
        'Assessment Score - Manager',
        'Assessment Score - Regular',
        'Assessment Score - Supervisor',
        'Associates Developer',
        'Client',
        'Client Development - Supervisor',
        'Client Development - User',
        'Email Templates',
        'In-Canada Module',
        'In-Canada Module - Supervisor',
        'Leads Only',
        'LMIA Inquiry - Regular User',
        'LMIA Lead - Regular',
        'LMIA Lead - Supervisor',
        'Only Email',
        'Recruiter',
        'Representatives Supervisor - Dildar',
        'Sales Representatives',
        'Sales Representatives - Dildar',
        'Sales Representatives - Jiya',
        'Study - Supervisor',
        'Study - New Regular',
        'USA - Regular',
        'USA - Supervisor',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                ['is_system' => $name === 'Administrator'],
            );
        }
    }
}
