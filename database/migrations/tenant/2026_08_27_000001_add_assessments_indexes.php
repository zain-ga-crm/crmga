<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKEND_BRIEF §4's index table applies per-column, not only to Contactable
 * entities: "Required on every entity: assigned_user_id, deleted_at,
 * created_at, primary_email, phone_mobile, ...". `assessments` isn't a
 * Contactable record (no shared macro -- see its own migration comment) but
 * independently carries both `phone_mobile` and `created_at`, and neither
 * was indexed (follow-up to the P5 audit fix, which only covered the 6
 * tables using the Contactable macro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->index('created_at', 'idx_assessments_created_at');
            $table->index('phone_mobile', 'idx_assessments_phone_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->dropIndex('idx_assessments_created_at');
            $table->dropIndex('idx_assessments_phone_mobile');
        });
    }
};
