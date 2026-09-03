<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Field-level ACL (STUDIO_API_RBAC.md §3.2/Appendix A2, the other half of the
// deferred item alongside Group-level access): one row per (role, module,
// field) that narrows a field to read-only or hides it entirely. Absence of a
// row means unrestricted ('read_write') -- this table only ever narrows,
// never widens, module-level access.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_field_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->string('field_name', 60);

            // 'read_write' | 'read_only' | 'hidden'. Named levels, never
            // integers -- same convention as role_module_permissions.
            $table->string('access', 10)->default('read_write');

            $table->timestamps();
            $table->unique(['role_id', 'module_key', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_field_permissions');
    }
};
