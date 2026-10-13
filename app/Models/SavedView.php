<?php

namespace App\Models;

use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S-2.4: a user's own named filter/search/sort combination for one module's
 * list page. Personal, never shared -- every query against this model should
 * be scoped to the signed-in user (see HasSavedViews::savedViewsQuery()).
 *
 * @property string $id
 * @property string $user_id
 * @property string $module_key
 * @property string $name
 * @property array<string, mixed>|null $filters
 * @property string|null $search
 * @property string|null $sort_column
 * @property string|null $sort_direction
 */
class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = ['user_id', 'module_key', 'name', 'filters', 'search', 'sort_column', 'sort_direction'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
