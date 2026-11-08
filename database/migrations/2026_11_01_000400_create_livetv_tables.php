<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['m3u', 'hdhomerun', 'dvb']);
            $table->text('url')->nullable();          // encrypted cast (may contain credentials)
            $table->text('epg_url')->nullable();
            $table->unsignedSmallInteger('max_streams')->default(1); // tuners / subscription limit
            $table->boolean('legal_ack')->default(false);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_source_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('number', 16)->nullable();
            $table->string('category', 32)->nullable(); // sports, news, kids...
            $table->string('epg_id')->nullable()->index();
            $table->text('stream_url');                  // encrypted
            $table->string('logo_path')->nullable();
            $table->unsignedTinyInteger('age_rating')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('epg_programs', function (Blueprint $table) {
            $table->id();
            $table->string('epg_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
        });

        Schema::create('favorite_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->unique(['profile_id', 'channel_id']);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['scheduled', 'recording', 'done', 'failed', 'cancelled'])->default('scheduled');
            $table->string('output_path', 1024)->nullable();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pid')->nullable();
            $table->string('output_dir', 1024);
            $table->enum('status', ['starting', 'running', 'reconnecting', 'stopped'])->default('starting');
            $table->unsignedSmallInteger('restarts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('live_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->string('viewer_key', 64);
            $table->timestamp('last_seen_at');
            $table->unique(['live_stream_id', 'viewer_key']);
        });
    }

    public function down(): void
    {
        foreach (['live_viewers', 'live_streams', 'recordings', 'favorite_channels', 'epg_programs', 'channels', 'live_sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
