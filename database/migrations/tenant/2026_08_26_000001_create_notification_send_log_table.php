<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKEND_BRIEF §11 -- scheduled jobs "must be idempotent" and the daily report
 * is spec'd as "guarded by a log table so it can never send twice for one day".
 * One generic dedup log for every scheduled notification: a job claims a key
 * (e.g. "daily_count_report:2026-08-26" or "follow_up:lead:{id}:2026-08-26")
 * by inserting it here before sending; the unique index makes a second attempt
 * at the same key fail atomically, so this also survives concurrent workers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_send_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedup_key')->unique();
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_send_log');
    }
};
