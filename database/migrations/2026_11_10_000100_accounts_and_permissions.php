<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No more "who's watching": every person has their own account (username or email + password).
 * Viewing state stays in one internal profile per account. Access is governed by per-user permissions,
 * an age limit and an optional list of allowed libraries — all set by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 60)->nullable()->unique()->after('name');
            $t->json('permissions')->nullable();
            $t->unsignedTinyInteger('max_age_rating')->nullable();
            $t->json('allowed_library_ids')->nullable();
            $t->timestamp('disabled_at')->nullable();
        });
        // downloads_enabled becomes the "download" permission
        foreach (DB::table('users')->get(['id', 'downloads_enabled']) as $u) {
            DB::table('users')->where('id', $u->id)->update(['permissions' => json_encode(array_values(array_filter(['live', 'party', $u->downloads_enabled ? 'download' : null])))]);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['downloads_enabled', 'parental_pin_hash']));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('downloads_enabled')->default(false);
            $t->string('parental_pin_hash')->nullable();
            $t->dropUnique(['username']);
            $t->dropColumn(['username', 'permissions', 'max_age_rating', 'allowed_library_ids', 'disabled_at']);
        });
    }
};
