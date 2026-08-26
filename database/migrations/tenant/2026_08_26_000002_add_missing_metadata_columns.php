<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKEND_BRIEF §5.1 defines these columns on the metadata tables; they were
 * never added when the tables were first created (audit finding, P4):
 *
 * - fields.label: the human-entered label, distinct from label_key (the
 *   generated LBL_* key). Nullable -- every code path that creates a Field
 *   row now populates it, but the column itself can't demand that.
 * - modules.label_plural, modules.menu_group, modules soft-delete.
 * - option_items.is_active: lets an item be hidden from new selections
 *   without deleting it (existing records that already hold the value are
 *   unaffected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fields', function (Blueprint $table): void {
            $table->string('label')->nullable()->after('name');
        });

        Schema::table('modules', function (Blueprint $table): void {
            $table->string('label_plural')->nullable()->after('label');
            $table->string('menu_group')->nullable()->after('icon');
            $table->softDeletes();
        });

        Schema::table('option_items', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('option_items', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn(['label_plural', 'menu_group', 'deleted_at']);
        });

        Schema::table('fields', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};
