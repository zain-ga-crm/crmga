<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * affiliates.status and newsletter_subscribers.status were NOT NULL with a DB
 * default (unlike every sibling "status" column -- company_contact_status,
 * students.status, clients.client_status -- which are all nullable). A DB
 * default only applies when a column is OMITTED from an INSERT; Filament's
 * dynamic create form submits an explicit NULL for an untouched, non-required
 * text field, which a NOT NULL column rejects outright regardless of its
 * default. Found via AffiliateResourceTest's own create-form test (S-4.1).
 * No doctrine/dbal in this project, so a raw MODIFY rather than ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `affiliates` MODIFY COLUMN `status` VARCHAR(30) NULL DEFAULT \'active\'');
        DB::statement('ALTER TABLE `newsletter_subscribers` MODIFY COLUMN `status` VARCHAR(30) NULL DEFAULT \'subscribed\'');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `affiliates` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT \'active\'');
        DB::statement('ALTER TABLE `newsletter_subscribers` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT \'subscribed\'');
    }
};
