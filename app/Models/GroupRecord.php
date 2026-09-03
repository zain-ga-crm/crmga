<?php

namespace App\Models;

use Database\Factories\GroupRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per (group, record) grant -- a record may belong to more than one
 * group. `recordable_type` is always a fully-qualified Aclable model class,
 * matching `get_class($model)` exactly (never a module key), since that's
 * what `AppliesRecordAccess`/`CrmPolicy` query against.
 *
 * @property string $id
 * @property string $group_id
 * @property string $recordable_type
 * @property string $recordable_id
 */
class GroupRecord extends Model
{
    /** @use HasFactory<GroupRecordFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = ['group_id', 'recordable_type', 'recordable_id'];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return MorphTo<Model, $this> */
    public function recordable(): MorphTo
    {
        return $this->morphTo();
    }
}
