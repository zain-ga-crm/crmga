<?php

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'module_key' => 'leads',
            'name' => 'My view',
            'filters' => [],
            'search' => null,
            'sort_column' => null,
            'sort_direction' => null,
        ];
    }
}
