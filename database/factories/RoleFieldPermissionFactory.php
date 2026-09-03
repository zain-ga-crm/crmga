<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleFieldPermission;
use App\Support\Acl\FieldAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleFieldPermission>
 */
class RoleFieldPermissionFactory extends Factory
{
    protected $model = RoleFieldPermission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'module_key' => fake()->unique()->word(),
            'field_name' => fake()->unique()->word(),
            'access' => FieldAccess::ReadWrite,
        ];
    }
}
