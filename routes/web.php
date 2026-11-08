<?php

use App\Modules\Core\Http\Controllers\Admin\AdminController;
use App\Modules\Core\Http\Controllers\Admin\LiveAdminController;
use App\Modules\Core\Http\Controllers\OidcController;
use App\Modules\Core\Http\Controllers\ProfileController;
use App\Modules\Core\Http\Controllers\TwoFactorController;
use App\Modules\Library\Http\Controllers\BrowseController;
use App\Modules\LiveTV\Http\Controllers\LiveController;
use App\Modules\Media\Http\Controllers\MediaAccessController;
use App\Modules\Playback\Http\Controllers\PlaybackController;
use App\Modules\Playback\Http\Controllers\WatchPartyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('home') : redirect()->route('login'));

// HomeCore SSO (OIDC + PKCE)
Route::get('auth/homecore', [OidcController::class, 'redirect'])->name('oidc.redirect');
Route::get('auth/homecore/callback', [OidcController::class, 'callback'])->name('oidc.callback');

// Two-factor challenge (between password and session)
Route::middleware('guest')->group(function () {
    Route::get('two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::get('profiles', [ProfileController::class, 'select'])->name('profiles.select');
    Route::post('profiles/{profile}/enter', [ProfileController::class, 'enter'])->middleware('throttle:20,1')->name('profiles.enter');
    Route::post('profiles', [ProfileController::class, 'store'])->name('profiles.store');
    Route::patch('profiles/{profile}', [ProfileController::class, 'update'])->name('profiles.update');
    Route::delete('profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');
    Route::put('settings/parental-pin', [ProfileController::class, 'parentalPin'])->name('parental-pin.update');

    Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('settings/two-factor', [TwoFactorController::class, 'start'])->name('two-factor.start');
    Route::put('settings/two-factor', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');

    // The single gateway to media bytes (signed token in the path + session + profile check)
    Route::get('m/{token}/{path?}', MediaAccessController::class)->where('path', '[A-Za-z0-9_\-./]+')->name('media');

    Route::middleware('profile')->group(function () {
        Route::get('home', [BrowseController::class, 'home'])->name('home');
        Route::get('search', [BrowseController::class, 'search'])->name('search');
        Route::get('history', [BrowseController::class, 'history'])->name('history');
        Route::get('title/{item}', [BrowseController::class, 'show'])->name('title');
        Route::post('title/{item}/list', [BrowseController::class, 'toggleList'])->name('title.list');
        Route::post('title/{item}/rate', [BrowseController::class, 'rate'])->name('title.rate');
        Route::put('profile/preferences', [ProfileController::class, 'preferences'])->name('profile.preferences');

        Route::get('watch/{file}', [PlaybackController::class, 'page'])->name('watch');
        Route::post('playback/{file}/start', [PlaybackController::class, 'start'])->name('playback.start');
        Route::post('playback/session/{session}/progress', [PlaybackController::class, 'progress'])->name('playback.progress');
        Route::post('playback/session/{session}/stop', [PlaybackController::class, 'stop'])->name('playback.stop');
        Route::get('playback/job/{job}', [PlaybackController::class, 'status'])->name('playback.status');
        Route::post('watch/{file}/subtitles', [PlaybackController::class, 'uploadSubtitle'])->name('subtitles.upload');

        Route::post('party/{file}', [WatchPartyController::class, 'create'])->name('party.create');
        Route::get('party/{uuid}/state', [WatchPartyController::class, 'state'])->name('party.state');
        Route::post('party/{uuid}/state', [WatchPartyController::class, 'update'])->name('party.update');
        Route::post('party/{uuid}/messages', [WatchPartyController::class, 'message'])->middleware('throttle:30,1')->name('party.message');

        Route::get('live', [LiveController::class, 'index'])->name('live');
        Route::post('live/{channel}/watch', [LiveController::class, 'watch'])->name('live.watch');
        Route::get('live/stream/{stream}/status', [LiveController::class, 'status'])->name('live.status');
        Route::post('live/stream/{stream}/leave', [LiveController::class, 'leave'])->name('live.leave');
        Route::post('live/{channel}/favorite', [LiveController::class, 'favorite'])->name('live.favorite');

        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
            Route::post('libraries', [AdminController::class, 'storeLibrary'])->name('libraries.store');
            Route::delete('libraries/{library}', [AdminController::class, 'destroyLibrary'])->name('libraries.destroy');
            Route::post('libraries/{library}/scan', [AdminController::class, 'scan'])->name('libraries.scan');
            Route::patch('users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
            Route::get('matches', [AdminController::class, 'matches'])->name('matches');
            Route::post('files/{file}/rematch', [AdminController::class, 'rematch'])->name('files.rematch');
            Route::post('files/{file}/intro', [AdminController::class, 'setIntro'])->name('files.intro');
            Route::post('files/{file}/pretranscode', [AdminController::class, 'pretranscode'])->name('files.pretranscode');
            Route::patch('items/{item}', [AdminController::class, 'updateItem'])->name('items.update');
            Route::delete('transcodes/{job}', [AdminController::class, 'deleteTranscode'])->name('transcodes.destroy');
            Route::get('quarantine', [AdminController::class, 'quarantine'])->name('quarantine');
            Route::post('quarantine/{job}/retry', [AdminController::class, 'retry'])->name('quarantine.retry');
            Route::delete('quarantine/{job}', [AdminController::class, 'purge'])->name('quarantine.purge');
            Route::post('uploads', [AdminController::class, 'uploadInit'])->name('uploads.init');
            Route::put('uploads/{uuid}', [AdminController::class, 'uploadChunk'])->name('uploads.chunk');
            Route::get('live', [LiveAdminController::class, 'index'])->name('live');
            Route::post('live/sources', [LiveAdminController::class, 'storeSource'])->name('live.sources.store');
            Route::post('live/sources/{source}/refresh', [LiveAdminController::class, 'refresh'])->name('live.sources.refresh');
            Route::delete('live/sources/{source}', [LiveAdminController::class, 'destroySource'])->name('live.sources.destroy');
            Route::patch('live/channels/{channel}', [LiveAdminController::class, 'updateChannel'])->name('live.channels.update');
            Route::post('live/channels/{channel}/record', [LiveAdminController::class, 'record'])->name('live.record');
            Route::delete('live/recordings/{recording}', [LiveAdminController::class, 'cancelRecording'])->name('live.recordings.cancel');
        });
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
