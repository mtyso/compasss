<?php

namespace App\Models;

use App\Support\MonthDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** An expected income on a fixed day of the month (D15). */
class IncomeSchedule extends Model
{
    protected $fillable = ['name', 'day_of_month', 'expected_amount', 'currency', 'account_id', 'active'];

    protected function casts(): array
    {
        return [
            'day_of_month' => 'integer',
            'expected_amount' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** The next payday after today: today's own payday counts as already arrived once it is recorded. */
    public function nextDate(Carbon $today): Carbon
    {
        return MonthDay::next($this->day_of_month, $today, strictlyAfter: true);
    }

    /** @return array<string, mixed> */
    public function toApi(?Carbon $today = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'day_of_month' => $this->day_of_month,
            'expected_amount' => $this->expected_amount,
            'currency' => $this->currency,
            'account_id' => $this->account_id,
            'active' => $this->active,
            'next_date' => $this->nextDate($today ?? now())->toDateString(),
        ];
    }
}
