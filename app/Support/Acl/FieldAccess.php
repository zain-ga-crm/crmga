<?php

namespace App\Support\Acl;

/**
 * Field-level access (STUDIO_API_RBAC.md §3.2) — narrows a module-level grant
 * down to a single field. Never widens it: a field marked `hidden` here stays
 * hidden even if the module-level access is `all`.
 */
enum FieldAccess: string
{
    case ReadWrite = 'read_write';
    case ReadOnly = 'read_only';
    case Hidden = 'hidden';

    /**
     * Higher rank = more permissive. Used to pick the most permissive level
     * across a user's roles, the same convention as AccessLevel::rank().
     */
    public function rank(): int
    {
        return match ($this) {
            self::ReadWrite => 2,
            self::ReadOnly => 1,
            self::Hidden => 0,
        };
    }

    public static function mostPermissive(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
