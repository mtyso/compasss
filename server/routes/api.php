<?php

use App\Http\Controllers\Auth\TelegramLoginController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]);

Route::prefix('auth/telegram')->controller(TelegramLoginController::class)->group(function () {
    Route::post('/start', 'start')->middleware('throttle:10,1');
    Route::get('/status/{id}', 'status')->middleware('throttle:120,1');
});

Route::middleware(['auth:sanctum', 'allowed'])->group(function () {
    Route::post('/auth/logout', [TelegramLoginController::class, 'logout']);
    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
});
