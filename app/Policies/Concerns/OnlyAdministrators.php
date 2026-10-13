<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Z-7.1 (security review): defense-in-depth for the admin-only entities
 * (Role/User/Studio metadata) that only ever had page-level gating
 * (RoleResource/UserResource/FieldManager/DropdownEditor/LayoutEditor's
 * own `canAccess() => isAdmin()`) and no Laravel Policy at all -- Gate
 * checks against these models (e.g. a future getGlobalSearchResultUrl()
 * canEdit/canView call, or anything else that reaches for Gate::allows()
 * directly) previously fell through to "no policy registered = allow",
 * the same default-allow behaviour documented for CrmPolicy's own
 * entities, except here that default was never meant to apply: these
 * aren't ACL-scoped CRM modules, they're fixed admin-only config.
 *
 * Deliberately explicit methods, not a single `before()` catch-all --
 * tested and confirmed the hard way: Gate::resolvePolicyCallback() only
 * invokes a policy's before() if the policy ALSO has a method matching
 * the ability name (is_callable([$policy, $method]) gates whether
 * before() ever runs at all); a before()-only policy with no named
 * methods silently resolves to "no callback", which denies everything,
 * including to an admin.
 */
trait OnlyAdministrators
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user): bool
    {
        return $user->isAdmin();
    }
}
