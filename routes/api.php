<?php

use App\Modules\Core\Http\Controllers\HealthController;
use App\Modules\Integrations\Http\Controllers\CloudSyncController;
use App\Modules\Integrations\Http\Controllers\HomeCoreController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('api.health');

Route::prefix('homecore')->middleware(['api.token:homecore:read', 'throttle:120,1'])->group(function () {
    Route::get('continue-watching', [HomeCoreController::class, 'continueWatching']);
    Route::get('poster/{item}', [HomeCoreController::class, 'poster']);
    Route::get('now-streaming', [HomeCoreController::class, 'nowStreaming']);
    Route::get('transcodes', [HomeCoreController::class, 'transcodes']);
    Route::get('search', [HomeCoreController::class, 'search']);
});

Route::prefix('cloudsync')->middleware(['api.token:cloudsync:ingest', 'throttle:60,1'])->group(function () {
    Route::post('link', [CloudSyncController::class, 'link']);
    Route::post('send', [CloudSyncController::class, 'send']);
    Route::get('ingest/{id}', [CloudSyncController::class, 'status']);
});
