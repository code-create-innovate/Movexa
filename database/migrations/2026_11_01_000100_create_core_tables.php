<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_emergency_admin')->default(false)->after('is_admin');
            $table->string('locale', 5)->default('ar')->after('is_emergency_admin');
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('oidc_subject')->nullable()->unique();
            $table->string('parental_pin_hash')->nullable();
            $table->unsignedSmallInteger('max_concurrent_streams')->nullable();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('avatar_color', 16)->default('#e50914');
            $table->string('pin_hash')->nullable();
            $table->boolean('is_kid')->default(false);
            $table->unsignedTinyInteger('max_age_rating')->nullable(); // 0..18, used when is_kid
            $table->string('locale', 5)->nullable();
            $table->json('subtitle_prefs')->nullable();
            $table->string('preferred_audio_lang', 8)->nullable();
            $table->string('preferred_subtitle_lang', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_subject']);
            $table->dropColumn(['is_admin', 'is_emergency_admin', 'locale', 'two_factor_secret', 'two_factor_confirmed_at', 'oidc_subject', 'parental_pin_hash', 'max_concurrent_streams']);
        });
    }
};
