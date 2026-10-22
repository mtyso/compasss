<?php

use App\Models\LoginRequest;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [LoginRequest::class]])->daily();

// Operations fetch a missing rate on their own; this keeps today's rate ready before the day starts.
Schedule::command('rates:fetch')->dailyAt('09:30')->timezone('Asia/Tashkent');
