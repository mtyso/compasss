<?php

namespace App\Telegram\Handlers;

use App\Habits\HabitProgress;
use App\Models\User;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;

/**
 * The task and habit buttons under the day's summary: «☑️ Позвонить в банк» ticks a task off
 * (a repeating one moves to its next date), «✓ Зарядка» / «+1 Вода» logs today's habit.
 */
class ThingsFlow
{
    public function __construct(private TelegramClient $telegram, private Cards $cards) {}

    public function onCallback(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        if (! preg_match('/^(task:done|habit:inc):(\d+)$/', $query['data'] ?? '', $m)) {
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }

        $toast = $m[1] === 'task:done' ? $this->completeTask($user, (int) $m[2]) : $this->logHabit($user, (int) $m[2]);
        if ($toast === null) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $l));

            return;
        }

        $this->telegram->answerCallbackQuery($query['id'], $toast);
        // The summary above is now out of date: redraw it, as after «Оплачено».
        $this->telegram->editCard($chatId, $messageId, $this->cards->today($user), $this->cards->todayKeyboard($user));
    }

    /** Same as «Готово» in the app (TaskController::complete). */
    private function completeTask(User $user, int $id): ?string
    {
        // Only one in today's list: a second tap must not push a repeating task one more step ahead.
        $task = $user->tasks()->whereNull('done_at')->where('due_on', '<=', now()->toDateString())->find($id);
        if (! $task) {
            return null;
        }

        $task->complete(now());

        return $task->done_at === null && $task->dueDate()
            ? __('bot.things.task_next', ['date' => Fmt::date($task->dueDate(), $user->locale)], $user->locale)
            : __('bot.things.task_done', ['title' => $task->title], $user->locale);
    }

    /**
     * A check habit becomes done (only done: a double tap must not undo it, the app does that);
     * a count or limit one gets one more for today.
     */
    private function logHabit(User $user, int $id): ?string
    {
        $habit = $user->habits()->whereNull('archived_at')->find($id);
        if (! $habit) {
            return null;
        }

        $today = now()->startOfDay();
        $date = $today->toDateString();
        $current = HabitProgress::for($habit, $today)->value($today);
        $value = $habit->type === 'check' ? 1 : $current + 1;

        // As HabitController::log for today.
        $habit->logs()->updateOrCreate(['date' => $date], ['value' => $value, 'user_id' => $habit->user_id]);

        if ($habit->type === 'check') {
            return __('bot.things.habit_done', ['name' => $habit->name], $user->locale);
        }

        return __('bot.things.habit_logged', ['name' => $habit->name, 'progress' => $this->cards->habitProgress($habit, $value, $user->locale)], $user->locale);
    }
}
