<?php

namespace App\Models\Metadata;

use Database\Factories\Metadata\OptionItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $option_list_id
 * @property string $value
 * @property string $label
 * @property string|null $color
 * @property bool $is_active
 * @property int $sort_order
 */
class OptionItem extends Model
{
    /** @use HasFactory<OptionItemFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = ['option_list_id', 'value', 'label', 'color', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<OptionList, $this> */
    public function optionList(): BelongsTo
    {
        return $this->belongsTo(OptionList::class);
    }
}
