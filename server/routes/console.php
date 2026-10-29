<?php

use App\Models\CalendarReminder;
use App\Models\LoginRequest;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [LoginRequest::class, CalendarReminder::class]])->daily();

// Operations fetch a missing rate on their own; this keeps today's rate ready before the day starts.
Schedule::command('rates:fetch')->dailyAt('09:30')->timezone('Asia/Tashkent');

// The advisor's time-of-day rules (docs/PLAN.md, 2.5). «Сегодня» runs the morning ones itself if this is late.
Schedule::command('advisor:run morning')->dailyAt('08:00')->timezone('Asia/Tashkent');
Schedule::command('advisor:run evening')->dailyAt('21:00')->timezone('Asia/Tashkent');

// Bot messages (docs/BOT.md), after the advisor commands above so a summary carries what they found.
// Times are settings in config/telegram.php; the evening «nothing recorded» comes from advisor:run evening.
Schedule::command('bot:digest morning')->dailyAt(config('telegram.digest.morning_at'))->timezone('Asia/Tashkent');
// After advisor:run evening and before bot:notify: «ничего не записано» and the streaks at risk in one message.
Schedule::command('bot:digest evening')->dailyAt(config('telegram.digest.evening_at'))->timezone('Asia/Tashkent');
Schedule::command('bot:digest weekly')->weeklyOn(0, config('telegram.digest.weekly_at'))->timezone('Asia/Tashkent');
// Last month's report on the 1st, in the evening: a day to record what the last day left out.
Schedule::command('bot:digest monthly')->monthlyOn(1, config('telegram.digest.weekly_at'))->timezone('Asia/Tashkent');
Schedule::command('bot:notify')->everyMinute();

// The money streaks (docs/PLAN.md, D38): a day's limit can only be written on that day. Hourly, so a missed
// run costs an hour of the day, not the day; the 23:55 run is the day's result.
Schedule::command('streaks:close-day')->hourlyAt(55)->timezone('Asia/Tashkent');
