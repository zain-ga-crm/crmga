<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-2.4 — the "saved views" piece of the list-page framework that shipped
 * with everything else (column chooser, filter panel, bulk-action bar,
 * export) except this. Personal to the user who created it, per module --
 * no sharing/team visibility in v1, matching how every other per-user
 * preference in this app works (e.g. a user's own 2FA state).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module_key');
            $table->string('name');
            $table->json('filters')->nullable();
            $table->string('search')->nullable();
            $table->string('sort_column')->nullable();
            $table->string('sort_direction')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'module_key', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
