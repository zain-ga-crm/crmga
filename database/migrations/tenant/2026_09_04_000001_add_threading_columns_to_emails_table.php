<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// IMAP intake job: RFC 5322 Message-ID/In-Reply-To let a reply thread onto the
// same subject record its parent was matched to, without re-resolving the
// sender's address every time. message_id also makes re-polling idempotent --
// a message already imported is skipped rather than duplicated.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('status');
            $table->string('in_reply_to')->nullable()->after('message_id');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->dropIndex(['message_id']);
            $table->dropColumn(['message_id', 'in_reply_to']);
        });
    }
};
