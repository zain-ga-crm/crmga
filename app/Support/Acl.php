<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use App\Support\Acl\FieldAccess;

/**
 * The ACL resolution algorithm (BACKEND_BRIEF §8.2). One implementation,
 * used by policies, the shared query scope and (later) the API — so the
 * interface and the API can never disagree about who sees what.
 */
final class Acl
{
    /**
     * @var list<string>
     */
    public const ACTIONS = ['view', 'list', 'edit', 'delete', 'import', 'export', 'mass_update'];

    public function effective(User $user, string $moduleKey, string $action): AccessLevel
    {
        if ($user->isAdmin()) {
            return AccessLevel::All;
        }

        $level = null;
        foreach ($user->roles as $role) {
            /** @var Role $role */
            $roleLevel = $role->permissionFor($moduleKey)?->level($action) ?? AccessLevel::NotSet;
            if ($roleLevel === AccessLevel::NotSet) {
                continue;
            }
            $level = $level === null ? $roleLevel : AccessLevel::mostPermissive($level, $roleLevel);
        }

        return $level ?? AccessLevel::None;
    }

    /**
     * Insert a 'none' row for every existing role for a newly registered module —
     * new capability is never granted implicitly (BACKEND_BRIEF §8.4).
     */
    public function registerModule(string $moduleKey): void
    {
        foreach (Role::query()->get() as $role) {
            $this->ensureRow($role->id, $moduleKey);
        }
    }

    /**
     * Symmetric backfill: a newly created role starts with an explicit 'none' row
     * for every existing module, so the permission matrix never has silent gaps.
     */
    public function registerRole(string $roleId): void
    {
        foreach (Module::query()->get() as $module) {
            $this->ensureRow($roleId, $module->key);
        }
    }

    /**
     * Field-level access (STUDIO_API_RBAC.md §3.2) -- narrows a field within a
     * module a user can otherwise see/edit. Absence of any matching row across
     * every one of the user's roles means unrestricted ('read_write'); this
     * never widens what effective() already grants at the module level, it
     * only ever narrows a single field further.
     */
    public function fieldAccess(User $user, string $moduleKey, string $fieldName): FieldAccess
    {
        if ($user->isAdmin()) {
            return FieldAccess::ReadWrite;
        }

        $access = null;
        foreach ($user->roles as $role) {
            /** @var Role $role */
            $roleAccess = $role->fieldPermissionFor($moduleKey, $fieldName)?->access;
            if ($roleAccess === null) {
                continue;
            }
            $access = $access === null ? $roleAccess : FieldAccess::mostPermissive($access, $roleAccess);
        }

        return $access ?? FieldAccess::ReadWrite;
    }

    /**
     * The group IDs a user belongs to, for the "Group" access level -- shared
     * by AppliesRecordAccess (query scope) and CrmPolicy (single-record check)
     * so both agree on membership without duplicating the query.
     *
     * @return list<string>
     */
    public function groupIdsFor(User $user): array
    {
        return array_values($user->groups->map(fn (Group $group): string => $group->id)->all());
    }

    private function ensureRow(string $roleId, string $moduleKey): void
    {
        RoleModulePermission::query()->firstOrCreate(
            ['role_id' => $roleId, 'module_key' => $moduleKey],
            array_fill_keys(self::ACTIONS, AccessLevel::None),
        );
    }
}
