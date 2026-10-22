<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdvisorController;
use App\Http\Controllers\Auth\TelegramLoginController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\IncomeScheduleController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TransactionController;
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

    Route::apiResource('accounts', AccountController::class)->except('show')->parameters(['accounts' => 'id']);
    Route::apiResource('categories', CategoryController::class)->except('show')->parameters(['categories' => 'id']);
    Route::apiResource('transactions', TransactionController::class)->parameters(['transactions' => 'id']);
    Route::post('/transactions/{id}/restore', [TransactionController::class, 'restore']);
    Route::get('/summary', SummaryController::class);
    Route::get('/rates', RateController::class);

    Route::get('/budgets', [BudgetController::class, 'show']);
    Route::put('/budgets/{month}', [BudgetController::class, 'update']);
    Route::apiResource('income-schedules', IncomeScheduleController::class)->except('show')->parameters(['income-schedules' => 'id']);
    Route::apiResource('recurring-payments', RecurringPaymentController::class)->except('show')->parameters(['recurring-payments' => 'id']);
    Route::post('/recurring-payments/{id}/pay', [RecurringPaymentController::class, 'pay']);
    Route::get('/today', TodayController::class);

    Route::apiResource('tasks', TaskController::class)->except('show')->parameters(['tasks' => 'id']);
    Route::post('/tasks/{id}/restore', [TaskController::class, 'restore']);
    Route::post('/tasks/{id}/complete', [TaskController::class, 'complete']);
    Route::delete('/tasks/{id}/complete', [TaskController::class, 'reopen']);
    Route::apiResource('habits', HabitController::class)->except('show')->parameters(['habits' => 'id']);
    Route::post('/habits/{id}/restore', [HabitController::class, 'restore']);
    Route::put('/habits/{id}/logs/{date}', [HabitController::class, 'log']);

    Route::post('/advisor/can-afford', [AdvisorController::class, 'canAfford']);
    Route::get('/insights', [InsightController::class, 'index']);
    Route::post('/insights/seen', [InsightController::class, 'seen']);
    Route::post('/insights/{id}/feedback', [InsightController::class, 'feedback']);
    Route::post('/insights/{id}/action', [InsightController::class, 'act']);
});
