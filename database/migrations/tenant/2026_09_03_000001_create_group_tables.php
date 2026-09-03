<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ACL "Group" access level (STUDIO_API_RBAC.md §3.2/Appendix A2, deferred at
// launch): a team/security-group concept distinct from the reporting
// hierarchy on users.reports_to_id. A record can belong to more than one
// group (polymorphic, mirrors SuiteCRM's securitygroups_records), so this
// works for every Aclable entity without a schema change per module.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('group_user', function (Blueprint $table): void {
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['group_id', 'user_id']);
        });

        Schema::create('group_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->uuidMorphs('recordable');
            $table->timestamps();
            $table->unique(['group_id', 'recordable_type', 'recordable_id'], 'group_records_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_records');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};
