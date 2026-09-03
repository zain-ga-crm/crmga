<?php

namespace App\Models;

use App\Support\Acl\FieldAccess;
use Database\Factories\RoleFieldPermissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (role, module, field) narrowing that field to read-only or
 * hidden (STUDIO_API_RBAC.md §3.2). No row for a field means unrestricted.
 *
 * @property string $id
 * @property string $role_id
 * @property string $module_key
 * @property string $field_name
 * @property FieldAccess $access
 */
class RoleFieldPermission extends Model
{
    /** @use HasFactory<RoleFieldPermissionFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = ['role_id', 'module_key', 'field_name', 'access'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access' => FieldAccess::class];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
