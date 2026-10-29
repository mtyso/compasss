<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of the records journal (docs/PLAN.md, D39): a streak milestone or a new record, a goal step,
 * a debt closed, the cushion, a month in budget, a savings-rate record. Written by App\Streaks\RecordKeeper,
 * kept for good; removing a line hides it and keeps it from coming back.
 */
class Record extends Model
{
    use SoftDeletes;

    protected $fillable = ['kind', 'subject', 'ref', 'value', 'date', 'payload', 'notified_at'];

    // `date` stays uncast and is always written as Y-m-d.
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'payload' => 'array',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The line in the given language: «Без доставки: 30 дней подряд», «Dongfeng 008: собрано 50%, 60 000 000 сум». */
    public function text(?string $locale = null): string
    {
        $locale ??= $this->user?->locale ?? 'ru';
        $p = $this->payload ?? [];
        $count = (int) $this->value;
        $money = fn (string $key) => Format::money((int) ($p[$key] ?? 0), $p['currency'] ?? 'UZS', $locale);

        return match ($this->kind) {
            'streak_milestone', 'streak_record' => trans_choice(
                "records.{$this->kind}.".$this->streakKey(),
                $count,
                ['count' => $count, 'name' => $p['name'] ?? ''],
                $locale,
            ),
            'goal_first' => __('records.goal_first', ['goal' => $p['name'] ?? '', 'amount' => $money('amount')], $locale),
            'goal_step' => ($p['type'] ?? null) === 'debt'
                ? __('records.goal_step_debt', ['goal' => $p['name'] ?? '', 'percent' => $count], $locale)
                : __('records.goal_step', ['goal' => $p['name'] ?? '', 'percent' => $count, 'amount' => $money('amount')], $locale),
            'goal_done' => __(($p['type'] ?? null) === 'buy' ? 'records.goal_done_buy' : 'records.goal_done', ['goal' => $p['name'] ?? ''], $locale),
            'debt_closed' => __('records.debt_closed', ['name' => $p['name'] ?? ''], $locale),
            'cushion' => trans_choice('records.cushion', $count, ['count' => $count], $locale),
            'month_budget' => __('records.month_budget', [
                'month' => Format::month($this->subject, $locale),
                'spent' => Format::uzs((int) ($p['spent_uzs'] ?? 0), $locale),
                'limit' => Format::uzs((int) ($p['limit_uzs'] ?? 0), $locale),
            ], $locale),
            'savings_rate' => __('records.savings_rate', ['month' => Format::month($this->subject, $locale), 'percent' => $count], $locale),
            default => '',
        };
    }

    /** habit, habit_week, limit, records or contributions. */
    private function streakKey(): string
    {
        if (str_starts_with($this->subject, 'h')) {
            return ($this->payload['unit'] ?? 'day') === 'week' ? 'habit_week' : 'habit';
        }

        return $this->subject;
    }

    /** @return array<string, mixed> */
    public function toApi(?string $locale = null): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'subject' => $this->subject,
            'value' => $this->value,
            'date' => substr((string) $this->date, 0, 10),
            'text' => $this->text($locale),
            'ref' => $this->link(),
        ];
    }

    /** Where the line leads in the app: a habit or a goal, by id. @return array{type: string, id: int}|null */
    private function link(): ?array
    {
        $id = (int) substr($this->subject, 1);

        return match (true) {
            $id <= 0 => null,
            str_starts_with($this->subject, 'h') => ['type' => 'habit', 'id' => $id],
            str_starts_with($this->subject, 'g') => ['type' => 'goal', 'id' => $id],
            default => null,
        };
    }
}
