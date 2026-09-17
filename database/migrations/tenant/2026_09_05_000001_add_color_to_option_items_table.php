<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-1.4 (docs/TASK_BREAKDOWN.md): the badge set needs a per-option-value colour
 * for the UI to read (dropdown enum/multienum fields render as badges). A named
 * Filament colour (danger, warning, success, info, primary, gray, ...), not a
 * hex value -- so it stays theme-aware (light/dark) the same way every other
 * Filament colour in this app already is. Nullable: an item with no colour set
 * just gets Filament's own default badge styling, same as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_items', function (Blueprint $table): void {
            $table->string('color', 20)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('option_items', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
