<?php

use App\Models\User;
use Illuminate\Support\Carbon;

// Tasks (docs/PLAN.md, step 1.6): dates, priority, repeats, completing and undoing.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

function newTask(object $test, array $data): array
{
    return $test->postJson('/api/tasks', $data, $test->auth)->assertCreated()->json();
}

it('creates, lists and edits tasks', function () {
    newTask($this, ['title' => 'Someday', 'priority' => 0]);
    $call = newTask($this, ['title' => '  Позвонить маме  ', 'due_on' => '2026-10-21', 'due_time' => '19:30', 'priority' => 2]);
    newTask($this, ['title' => 'Overdue', 'due_on' => '2026-10-19']);

    expect($call)->toMatchArray(['title' => 'Позвонить маме', 'due_on' => '2026-10-21', 'due_time' => '19:30', 'priority' => 2, 'overdue' => false]);
    $this->getJson('/api/tasks', $this->auth)->assertOk()
        ->assertJsonPath('0.title', 'Overdue')->assertJsonPath('0.overdue', true)
        ->assertJsonPath('1.title', 'Позвонить маме')
        ->assertJsonPath('2.title', 'Someday');

    $this->patchJson("/api/tasks/{$call['id']}", ['due_on' => null, 'notes' => 'про выходные'], $this->auth)
        ->assertOk()->assertJsonPath('due_on', null)->assertJsonPath('notes', 'про выходные');
    $this->postJson('/api/tasks', ['title' => '', 'priority' => 5, 'due_time' => '25:00'], $this->auth)
        ->assertJsonValidationErrors(['title', 'priority', 'due_time']);
});

it('ticks a task off and reopens it', function () {
    $task = newTask($this, ['title' => 'Купить хлеб', 'due_on' => '2026-10-21']);

    $this->postJson("/api/tasks/{$task['id']}/complete", [], $this->auth)->assertOk()
        ->assertJsonPath('task.done_at', fn ($v) => $v !== null)->assertJsonPath('previous_due_on', null);
    $this->getJson('/api/tasks', $this->auth)->assertJsonCount(1);
    $this->getJson('/api/tasks?done=1', $this->auth)->assertJsonCount(1);

    $this->deleteJson("/api/tasks/{$task['id']}/complete", [], $this->auth)->assertOk()->assertJsonPath('done_at', null);

    // Finished more than three days ago drops out of the main list.
    $this->postJson("/api/tasks/{$task['id']}/complete", [], $this->auth);
    $this->travel(4)->days();
    $this->getJson('/api/tasks', $this->auth)->assertJsonCount(0);
});

it('moves a repeating task to its next date and can put it back', function () {
    $gym = newTask($this, ['title' => 'Зал', 'due_on' => '2026-10-21', 'repeat' => ['unit' => 'week', 'days' => [3, 1, 5]]]);
    expect($gym['repeat'])->toBe(['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]]);

    $this->postJson("/api/tasks/{$gym['id']}/complete", [], $this->auth)->assertOk()
        ->assertJsonPath('task.due_on', '2026-10-23')
        ->assertJsonPath('task.done_at', null)
        ->assertJsonPath('previous_due_on', '2026-10-21');
    $this->deleteJson("/api/tasks/{$gym['id']}/complete", ['due_on' => '2026-10-21'], $this->auth)->assertJsonPath('due_on', '2026-10-21');

    // A monthly task without a date starts today and keeps its day of month.
    $rent = newTask($this, ['title' => 'Аренда', 'repeat' => ['unit' => 'month']]);
    expect($rent)->toMatchArray(['due_on' => '2026-10-21', 'repeat' => ['unit' => 'month', 'every' => 1, 'day' => 21]]);
    $this->patchJson("/api/tasks/{$rent['id']}", ['due_on' => '2026-10-31'], $this->auth)->assertJsonPath('repeat.day', 31);
    $this->postJson("/api/tasks/{$rent['id']}/complete", [], $this->auth)->assertJsonPath('task.due_on', '2026-11-30');

    // Taking the date away stops the repeat.
    $this->patchJson("/api/tasks/{$rent['id']}", ['due_on' => null], $this->auth)->assertJsonPath('repeat', null);
});

it('keeps the day of a yearly task through a short February', function () {
    $birthday = newTask($this, ['title' => 'День рождения', 'due_on' => '2027-02-28', 'repeat' => ['unit' => 'year']]);
    expect($birthday['repeat'])->toBe(['unit' => 'year', 'every' => 1, 'day' => 28]);

    // Moved to the 29th of a leap year: it comes back on the 29th whenever there is one.
    $this->patchJson("/api/tasks/{$birthday['id']}", ['due_on' => '2028-02-29'], $this->auth)->assertJsonPath('repeat.day', 29);
    $this->travelTo(Carbon::parse('2028-02-29 12:00', 'Asia/Tashkent'));
    $this->postJson("/api/tasks/{$birthday['id']}/complete", [], $this->auth)->assertJsonPath('task.due_on', '2029-02-28');
    $this->travelTo(Carbon::parse('2031-03-01 12:00', 'Asia/Tashkent'));
    $this->postJson("/api/tasks/{$birthday['id']}/complete", [], $this->auth)
        ->assertJsonPath('task.due_on', '2032-02-29')->assertJsonPath('task.repeat.day', 29);
});

it('deletes softly and restores', function () {
    $task = newTask($this, ['title' => 'Temp']);
    $this->deleteJson("/api/tasks/{$task['id']}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/tasks', $this->auth)->assertJsonCount(0);
    $this->postJson("/api/tasks/{$task['id']}/restore", [], $this->auth)->assertOk()->assertJsonPath('title', 'Temp');

    $other = User::factory()->create();
    $foreign = $other->tasks()->create(['title' => 'Not yours']);
    $this->patchJson("/api/tasks/{$foreign->id}", ['title' => 'Mine'], $this->auth)->assertNotFound();
});
