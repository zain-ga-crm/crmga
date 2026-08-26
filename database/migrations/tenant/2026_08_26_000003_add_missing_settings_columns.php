<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKEND_BRIEF §12 defines `type`, `group` and `updated_by` on `settings`;
 * only `key`/`value`/`is_secret` were ever added (audit finding, P4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('type', 20)->nullable()->after('value');
            $table->string('group', 60)->nullable()->after('type');
            $table->foreignUuid('updated_by')->nullable()->after('is_secret')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['type', 'group', 'updated_by']);
        });
    }
};
