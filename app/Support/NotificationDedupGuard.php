<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotency guard for scheduled notifications (BACKEND_BRIEF §11: "guarded by
 * a log table so it can never send twice"). A job claims a key before sending;
 * a key already claimed today (or for whatever period the caller encodes into
 * it) is skipped.
 */
final class NotificationDedupGuard
{
    public function claim(string $key): bool
    {
        if (DB::table('notification_send_log')->where('dedup_key', $key)->exists()) {
            return false;
        }

        DB::table('notification_send_log')->insert([
            'id' => (string) Str::uuid(),
            'dedup_key' => $key,
            'sent_at' => now(),
        ]);

        return true;
    }
}
