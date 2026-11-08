<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $t) {
            $t->string('dominant_color', 7)->nullable();
            $t->json('tags')->nullable();
            $t->boolean('featured')->default(false);
            $t->string('preview_path')->nullable();
        });
        Schema::table('episodes', fn (Blueprint $t) => $t->text('overview_ar')->nullable());
        Schema::table('profiles', fn (Blueprint $t) => $t->json('settings')->nullable());
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('downloads_enabled')->default(false);
            $t->unsignedInteger('downloads_quota_mb')->default(8192);
            $t->unsignedSmallInteger('downloads_max')->default(10);
        });
        Schema::table('playback_sessions', fn (Blueprint $t) => $t->boolean('counted')->default(false));

        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('collection_media_item', function (Blueprint $t) {
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $t->primary(['collection_id', 'media_item_id']);
        });

        // Custom blocking: what (target) is hidden from whom (subject), optionally until a date.
        Schema::create('access_blocks', function (Blueprint $t) {
            $t->id();
            $t->enum('target_type', ['item', 'season', 'episode', 'genre', 'collection', 'tag', 'library', 'channel', 'channel_category']);
            $t->unsignedBigInteger('target_id')->nullable();
            $t->string('target_value')->nullable(); // genre / tag / channel category
            $t->enum('subject_type', ['profile', 'user']);
            $t->unsignedBigInteger('subject_id');
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 64);
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('kind', 16)->default('browser'); // tv | browser
            $t->string('session_id', 120)->nullable()->index();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('device_logins', function (Blueprint $t) {
            $t->id();
            $t->string('code', 6)->unique();
            $t->string('poll_hash', 64)->unique();
            $t->string('device_name', 120);
            $t->enum('status', ['pending', 'approved', 'consumed', 'expired'])->default('pending');
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('remote_devices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);
            $t->json('state')->nullable();
            $t->timestamp('state_at')->nullable();
            $t->unsignedBigInteger('last_seq')->default(0);
            $t->timestamps();
        });
        Schema::create('downloads', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('quality');
            $t->unsignedSmallInteger('audio_index')->default(0);
            $t->json('subtitle_ids')->nullable();
            $t->enum('status', ['queued', 'processing', 'ready', 'failed', 'revoked'])->default('queued');
            $t->unsignedTinyInteger('progress')->default(0);
            $t->string('output_path', 1024)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->decimal('position', 10, 2)->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('view_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $t->timestamp('counted_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['view_events', 'downloads', 'remote_devices', 'device_logins', 'devices', 'audit_logs', 'access_blocks', 'collection_media_item', 'collections'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('media_items', fn (Blueprint $t) => $t->dropColumn(['dominant_color', 'tags', 'featured', 'preview_path']));
        Schema::table('episodes', fn (Blueprint $t) => $t->dropColumn('overview_ar'));
        Schema::table('profiles', fn (Blueprint $t) => $t->dropColumn('settings'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['downloads_enabled', 'downloads_quota_mb', 'downloads_max']));
        Schema::table('playback_sessions', fn (Blueprint $t) => $t->dropColumn('counted'));
    }
};
