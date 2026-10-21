<?php

use App\Models\LoginRequest;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [LoginRequest::class]])->daily();
