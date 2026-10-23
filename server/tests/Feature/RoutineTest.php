<?php

use App\Models\User;
use Illuminate\Support\Carbon;

// The daily routine (распорядок дня): blocks by weekday, overnight ones, and a habit a block shows.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

function newBlock(array $data = []): array
{
    return test()->postJson('/api/routine', [
        'title' => 'Работа', 'kind' => 'work', 'start_time' => '09:00', 'end_time' => '18:00', 'days' => [1, 2, 3, 4, 5], ...$data,
    ], test()->auth)->assertCreated()->json();
}

it('creates, lists, edits, deletes and restores blocks', function () {
    $work = newBlock(['title' => '  Работа  ', 'days' => [5, 1, 3, 2, 4]]);
    $sleep = newBlock(['title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:00', 'end_time' => '07:00', 'days' => [7, 1, 2, 3, 4, 5, 6], 'remind' => true]);
    $evening = newBlock(['title' => 'Семья', 'kind' => 'family', 'start_time' => '20:00', 'end_time' => '00:00', 'days' => ['6', '7']]);

    expect($work)->toBe([
        'id' => $work['id'], 'habit_id' => null, 'title' => 'Работа', 'kind' => 'work', 'start_time' => '09:00', 'end_time' => '18:00',
        'days' => [1, 2, 3, 4, 5], 'remind' => false, 'sort' => 0, 'overnight' => false, 'minutes' => 540,
    ])
        ->and($sleep)->toMatchArray(['days' => [1, 2, 3, 4, 5, 6, 7], 'remind' => true, 'overnight' => true, 'minutes' => 480])
        ->and($evening)->toMatchArray(['days' => [6, 7], 'overnight' => true, 'minutes' => 240]);
    $this->getJson('/api/routine', $this->auth)->assertOk()->assertJsonPath('*.title', ['Работа', 'Семья', 'Сон']);

    $this->patchJson("/api/routine/{$work['id']}", ['end_time' => '17:00', 'days' => [1, 2, 3, 4]], $this->auth)->assertOk()
        ->assertJsonPath('end_time', '17:00')->assertJsonPath('days', [1, 2, 3, 4])->assertJsonPath('minutes', 480);

    $this->deleteJson("/api/routine/{$work['id']}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/routine', $this->auth)->assertJsonCount(2);
    $this->postJson("/api/routine/{$work['id']}/restore", [], $this->auth)->assertOk()->assertJsonPath('title', 'Работа');
    $this->getJson('/api/routine', $this->auth)->assertJsonCount(3);
});

it('validates blocks', function () {
    $this->postJson('/api/routine', [], $this->auth)->assertJsonValidationErrors(['title', 'kind', 'start_time', 'end_time', 'days']);
    $this->postJson('/api/routine', ['title' => str_repeat('я', 61), 'kind' => 'nap', 'start_time' => '9:00', 'end_time' => '24:00', 'days' => [0, 8]], $this->auth)
        ->assertJsonValidationErrors(['title', 'kind', 'start_time', 'end_time', 'days.0', 'days.1']);
    $this->postJson('/api/routine', ['title' => 'X', 'kind' => 'rest', 'start_time' => '09:00', 'end_time' => '10:00', 'days' => []], $this->auth)
        ->assertJsonValidationErrors('days');
    $this->postJson('/api/routine', ['title' => 'X', 'kind' => 'rest', 'start_time' => '09:00', 'end_time' => '10:00', 'days' => [1, 1]], $this->auth)
        ->assertJsonValidationErrors('days.0');
    $this->postJson('/api/routine', ['title' => 'X', 'kind' => 'rest', 'start_time' => '09:00', 'end_time' => '09:00', 'days' => [1]], $this->auth)
        ->assertJsonValidationErrors(['end_time' => 'Начало и конец совпадают.']);

    // A time sent alone is held against the stored other one.
    $block = newBlock();
    $this->patchJson("/api/routine/{$block['id']}", ['start_time' => '18:00'], $this->auth)->assertJsonValidationErrors('end_time');
});

it('links a habit in use, and forgets it once the habit is archived or deleted', function () {
    $habit = $this->postJson('/api/habits', ['name' => 'Спорт', 'type' => 'check', 'schedule' => ['days' => [1, 3, 5]]], $this->auth)->json();
    $other = User::factory()->create()->habits()->create(['name' => 'Чужая', 'type' => 'check', 'schedule' => ['days' => [1]], 'started_on' => '2026-10-01']);

    $this->postJson('/api/routine', ['title' => 'X', 'kind' => 'sport', 'start_time' => '19:00', 'end_time' => '20:00', 'days' => [1], 'habit_id' => $other->id], $this->auth)
        ->assertJsonValidationErrors('habit_id');
    $sport = newBlock(['title' => 'Спорт', 'kind' => 'sport', 'start_time' => '19:00', 'end_time' => '20:00', 'days' => [1, 3, 5], 'habit_id' => $habit['id']]);
    expect($sport['habit_id'])->toBe($habit['id']);

    $this->patchJson("/api/habits/{$habit['id']}", ['archived' => true], $this->auth)->assertOk();
    $this->getJson('/api/routine', $this->auth)->assertJsonPath('0.habit_id', null);
    $this->patchJson("/api/routine/{$sport['id']}", ['habit_id' => $habit['id']], $this->auth)->assertJsonValidationErrors('habit_id');

    $this->patchJson("/api/habits/{$habit['id']}", ['archived' => false], $this->auth)->assertOk();
    $this->getJson('/api/routine', $this->auth)->assertJsonPath('0.habit_id', $habit['id']);
    $this->deleteJson("/api/habits/{$habit['id']}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/routine', $this->auth)->assertJsonPath('0.habit_id', null);

    $this->patchJson("/api/routine/{$sport['id']}", ['habit_id' => null], $this->auth)->assertOk()->assertJsonPath('habit_id', null);
});

it('keeps blocks to their owner', function () {
    $foreign = User::factory()->create()->routineBlocks()->create([
        'title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:00', 'end_time' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7],
    ]);

    $this->getJson('/api/routine', $this->auth)->assertJsonCount(0);
    $this->patchJson("/api/routine/{$foreign->id}", ['title' => 'Мой'], $this->auth)->assertNotFound();
    $this->deleteJson("/api/routine/{$foreign->id}", [], $this->auth)->assertNotFound();
    $foreign->delete();
    $this->postJson("/api/routine/{$foreign->id}/restore", [], $this->auth)->assertNotFound();
});
