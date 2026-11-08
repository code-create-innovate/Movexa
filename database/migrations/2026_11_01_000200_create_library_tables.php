<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['movies', 'shows']);
            $table->string('path', 1024);
            $table->enum('source', ['local', 'cloudsync'])->default('local');
            $table->boolean('watch')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });

        // A movie or a show (series). Episodes hang below seasons.
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['movie', 'show']);
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->string('original_title')->nullable();
            $table->text('overview')->nullable();
            $table->text('overview_ar')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('age_rating')->nullable(); // normalized 0..18, null = unknown
            $table->string('certification', 16)->nullable();
            $table->json('genres')->nullable();
            $table->json('cast')->nullable();
            $table->decimal('vote_average', 3, 1)->nullable();
            $table->unsignedBigInteger('tmdb_id')->nullable();
            $table->string('poster_path')->nullable();   // relative to data/images
            $table->string('backdrop_path')->nullable();
            $table->boolean('match_locked')->default(false); // manual correction
            $table->string('match_key')->nullable()->index(); // normalized title+year from filename
            $table->timestamps();
            $table->index(['type', 'title']);
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title')->nullable();
            $table->string('poster_path')->nullable();
            $table->timestamps();
            $table->unique(['media_item_id', 'number']);
        });

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('overview')->nullable();
            $table->string('still_path')->nullable();
            $table->timestamps();
            $table->unique(['season_id', 'number']);
        });

        // A physical, sanitized file in the library. Never visible before status=ready.
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('library_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 1024);            // absolute path of the clean file
            $table->string('original_name');
            $table->string('sha256', 64)->nullable()->index();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('container', 32)->nullable();
            $table->string('video_codec', 32)->nullable();
            $table->string('audio_codec', 32)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('duration', 10, 3)->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->json('probe')->nullable();
            $table->boolean('sanitized')->default(false);
            $table->boolean('linked')->default(false); // CloudSync link, not copied
            $table->enum('status', ['pending', 'scanning', 'ready', 'quarantined', 'rejected'])->default('pending');
            $table->decimal('intro_start', 8, 2)->nullable();
            $table->decimal('intro_end', 8, 2)->nullable();
            $table->string('thumbnails_path')->nullable();
            $table->timestamps();
        });

        Schema::create('media_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('index');
            $table->enum('type', ['video', 'audio', 'subtitle']);
            $table->string('codec', 32)->nullable();
            $table->string('language', 8)->nullable();
            $table->string('title')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_text')->default(true);
            $table->timestamps();
        });

        Schema::create('subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->string('language', 8)->nullable();
            $table->string('label');
            $table->string('path', 1024); // sanitized UTF-8 WebVTT
            $table->enum('source', ['embedded', 'external', 'upload']);
            $table->string('source_encoding', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('ingest_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 24); // upload, scan, cloudsync, samba, external
            $table->string('original_name');
            $table->string('inbox_path', 1024)->nullable();
            $table->string('quarantine_path', 1024)->nullable();
            $table->string('detected_mime')->nullable();
            $table->enum('status', ['queued', 'checking', 'scanning', 'sanitizing', 'verifying', 'done', 'rejected', 'quarantined', 'failed'])->default('queued');
            $table->string('reason')->nullable();
            $table->string('threat')->nullable();
            $table->boolean('sanitized')->default(false);
            $table->boolean('link_only')->default(false);
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingest_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('sha256', 64)->nullable();
            $table->string('stage', 32);
            $table->string('result', 32);
            $table->string('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('file_name');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedInteger('chunk_size');
            $table->boolean('completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['uploads', 'security_events', 'ingest_jobs', 'subtitles', 'media_streams', 'media_files', 'episodes', 'seasons', 'media_items', 'libraries'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
