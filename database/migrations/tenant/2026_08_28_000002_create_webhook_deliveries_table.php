<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per delivery *attempt* (not per subscription-event), so a retried
 * delivery keeps its full history instead of overwriting the previous
 * attempt's result -- this is the "delivery log" §2.2 asks for, and what a
 * replay (crm:webhook-replay) reads to reconstruct exactly what was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
