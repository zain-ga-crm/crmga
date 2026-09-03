<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GroupRecord>
 */
class GroupRecordFactory extends Factory
{
    protected $model = GroupRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'recordable_type' => '',
            'recordable_id' => (string) Str::uuid(),
        ];
    }
}
