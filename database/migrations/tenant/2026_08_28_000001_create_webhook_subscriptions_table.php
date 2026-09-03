<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §21/PROJECT_PLAN.md §6 -- outbound webhooks, deferred at launch, built here as
 * post-launch backend work. A subscription matches an exact event
 * ("leads.created"), a module wildcard ("leads.*"), or every event ("*").
 * `secret` is the HMAC-SHA256 signing key WebhookDispatcher uses to sign every
 * delivery -- encrypted at rest, same convention as Settings' own secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event');
            $table->string('url');
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['event', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
