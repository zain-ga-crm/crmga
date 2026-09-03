<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team/security group for the ACL "Group" access level (STUDIO_API_RBAC.md
 * §3.2) -- distinct from the users.reports_to_id reporting hierarchy. Members
 * see every record any of their groups has been given via `group_records`.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 */
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = ['name', 'description'];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user');
    }

    /** @return HasMany<GroupRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(GroupRecord::class);
    }
}
