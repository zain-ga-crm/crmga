<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKEND_BRIEF §4's index table requires `created_at` and `phone_mobile` on
 * every Contactable entity, and a `leads` composite on `(vertical, stage,
 * assigned_user_id)` — none of these were ever added (audit finding, P5).
 * Also `affiliates.status`, which every sibling status-bearing table
 * (company_contact_status, students.status, client_status,
 * newsletter_subscribers.status) already has an index for.
 */
return new class extends Migration
{
    private const CONTACTABLE_TABLES = ['leads', 'companies', 'students', 'clients', 'affiliates', 'newsletter_subscribers'];

    public function up(): void
    {
        foreach (self::CONTACTABLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->index('created_at', "idx_{$table}_created_at");
                $blueprint->index('phone_mobile', "idx_{$table}_phone_mobile");
            });
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['vertical', 'stage', 'assigned_user_id'], 'idx_leads_vertical_stage_assigned_user_id');
        });

        Schema::table('affiliates', function (Blueprint $table): void {
            $table->index('status', 'idx_affiliates_status');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table): void {
            $table->dropIndex('idx_affiliates_status');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('idx_leads_vertical_stage_assigned_user_id');
        });

        foreach (self::CONTACTABLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("idx_{$table}_created_at");
                $blueprint->dropIndex("idx_{$table}_phone_mobile");
            });
        }
    }
};
