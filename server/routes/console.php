<?php

use App\Models\LoginRequest;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [LoginRequest::class]])->daily();

// Operations fetch a missing rate on their own; this keeps today's rate ready before the day starts.
Schedule::command('rates:fetch')->dailyAt('09:30')->timezone('Asia/Tashkent');

// The advisor's time-of-day rules (docs/PLAN.md, 2.5). «Сегодня» runs the morning ones itself if this is late.
Schedule::command('advisor:run morning')->dailyAt('08:00')->timezone('Asia/Tashkent');
Schedule::command('advisor:run evening')->dailyAt('21:00')->timezone('Asia/Tashkent');
