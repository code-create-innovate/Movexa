<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('position', 10, 2)->default(0);
            $table->decimal('duration', 10, 2)->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamps();
            $table->unique(['profile_id', 'media_file_id']);
            $table->index(['profile_id', 'updated_at']);
        });

        Schema::create('watch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('watched_at')->useCurrent();
        });

        Schema::create('my_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['profile_id', 'media_item_id']);
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('value'); // -1 dislike, 1 like, 2 love
            $table->timestamps();
            $table->unique(['profile_id', 'media_item_id']);
        });

        // Active playback sessions (direct/remux/transcode/live) for stats and limits.
        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable();
            $table->enum('method', ['direct', 'remux', 'transcode', 'live']);
            $table->string('client')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('transcode_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['remux', 'transcode']);
            $table->string('profile_key', 64); // e.g. "a0-v1080,720,480"
            $table->enum('status', ['queued', 'running', 'done', 'failed'])->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('output_dir', 1024)->nullable();
            $table->unsignedBigInteger('output_size')->default(0);
            $table->string('encoder', 32)->nullable();
            $table->string('error')->nullable();
            $table->boolean('pinned')->default(false); // pre-transcode, exempt from LRU cleanup
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->unique(['media_file_id', 'profile_key']);
        });

        Schema::create('watch_parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('host_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->decimal('position', 10, 2)->default(0);
            $table->boolean('playing')->default(false);
            $table->timestamp('state_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_party_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('body', 500);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['watch_party_messages', 'watch_parties', 'transcode_jobs', 'playback_sessions', 'ratings', 'my_list', 'watch_history', 'watch_progress'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
