<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S-1.2 "forced first-login password change" (crmga_Frontend_Design_Spec.docx
// §7): null means the current password was system-generated (an admin
// invited this user, or it's a fresh seed) and must be changed before the
// user reaches anything else in the panel; set the moment they choose their
// own password, at first-login or via a normal reset.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
