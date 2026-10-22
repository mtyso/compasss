<?php

namespace App\Advisor;

use App\Goals\GoalPlanner;
use App\Goals\GoalsPlan;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Money\Balances;
use App\Money\BudgetStatus;
use App\Money\ExchangeRates;
use App\Money\Plan;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the rules look at, read lazily and once per run. Rules stay deterministic:
 * the same data and the same moment give the same findings.
 */
final class AdvisorContext
{
    public const STRICTNESS = ['soft', 'normal', 'strict'];

    public readonly Carbon $today;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly User $user,
        public readonly Carbon $now,
        public readonly string $event,
        public readonly ?Transaction $trigger = null,
    ) {
        $this->today = $now->copy()->startOfDay();
    }

    public function month(): string
    {
        return $this->now->format('Y-m');
    }

    /** ISO week such as 2026-W43: weekly insights repeat at most once in it. */
    public function week(): string
    {
        return $this->now->format('o-\WW');
    }

    /** soft, normal or strict (docs/PLAN.md, 2.5). */
    public function strictness(): string
    {
        $value = $this->user->setting('advisor_strictness', 'normal');

        return in_array($value, self::STRICTNESS, true) ? $value : 'normal';
    }

    /** A threshold that depends on how strict the advisor is. */
    public function pick(mixed $soft, mixed $normal, mixed $strict): mixed
    {
        return ['soft' => $soft, 'normal' => $normal, 'strict' => $strict][$this->strictness()];
    }

    public function setting(string $key, mixed $default): mixed
    {
        return $this->user->setting($key, $default);
    }

    public function limit(): DailyLimit
    {
        return $this->memo['limit'] ??= app(Plan::class)->limit($this->user, $this->now, $this->goals());
    }

    /** The goals and their plan for the current pay period (docs/PLAN.md, 2.6). */
    public function goals(): GoalsPlan
    {
        return $this->memo['goals'] ??= app(GoalPlanner::class)->plan($this->user, $this->now);
    }

    public function budget(): BudgetStatus
    {
        return $this->memo['budget'] ??= BudgetStatus::for($this->user, $this->month(), $this->now);
    }

    /** @return Collection<int, Category> all the user's categories by id, archived ones included */
    public function categories(): Collection
    {
        return $this->memo['categories'] ??= $this->user->categories()->get()->keyBy('id');
    }

    public function category(?int $id): ?Category
    {
        return $id === null ? null : $this->categories()->get($id);
    }

    public function rootOf(?int $id): ?Category
    {
        $category = $this->category($id);

        return $category?->parent_id ? $this->category($category->parent_id) : $category;
    }

    /** @return Collection<int, Transaction> this month's expenses so far */
    public function monthExpenses(): Collection
    {
        return $this->memo['monthExpenses'] ??= $this->expensesBetween($this->now->copy()->startOfMonth(), $this->now->copy()->startOfMonth()->addMonth());
    }

    /** @return Collection<int, Transaction> */
    public function expensesBetween(Carbon $from, Carbon $to): Collection
    {
        return $this->user->transactions()
            ->where('type', 'expense')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->get();
    }

    /** Everyday spending: not one-off and not a paid recurring payment. */
    public static function isEveryday(Transaction $t): bool
    {
        return ! $t->is_one_off && $t->recurring_payment_id === null;
    }

    public function firstTransactionAt(): ?Carbon
    {
        if (! array_key_exists('first', $this->memo)) {
            $first = $this->user->transactions()->min('occurred_at');
            $this->memo['first'] = $first ? Carbon::parse($first, config('app.timezone')) : null;
        }

        return $this->memo['first'];
    }

    /**
     * Up to three full months before this one that the app saw from the start
     * (the first operation on or before the 5th), newest first, as YYYY-MM.
     *
     * @return list<string>
     */
    public function historyMonths(): array
    {
        if (isset($this->memo['history'])) {
            return $this->memo['history'];
        }
        $first = $this->firstTransactionAt();
        $months = [];
        if ($first !== null) {
            $tracked = $first->day <= 5 ? $first->copy()->startOfMonth() : $first->copy()->startOfMonth()->addMonth();
            for ($i = 1; $i <= 3; $i++) {
                $start = $this->now->copy()->startOfMonth()->subMonthsNoOverflow($i);
                if ($start->lt($tracked)) {
                    break;
                }
                $months[] = $start->format('Y-m');
            }
        }

        return $this->memo['history'] = $months;
    }

    /**
     * Median regular spending per month (docs/PLAN.md, 2.1: «обычный уровень»), one-offs left out.
     * With $essentialOnly, only essential categories and without paid recurring payments: what daily life costs.
     * Null without a full month of history.
     */
    public function typicalMonthly(bool $essentialOnly = false): ?int
    {
        $key = 'typical'.($essentialOnly ? 'Essential' : '');
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $totals = [];
        foreach ($this->historyMonths() as $month) {
            $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();
            $totals[] = $this->expensesBetween($start, $start->copy()->addMonth())
                ->reject(fn (Transaction $t) => $t->is_one_off)
                ->filter(fn (Transaction $t) => ! $essentialOnly || ($t->recurring_payment_id === null && $this->category($t->category_id)?->necessity === 'essential'))
                ->sum('amount_uzs');
        }

        return $this->memo[$key] = $totals === [] ? null : self::median($totals);
    }

    /** Money on cushion accounts, in som. */
    public function cushionUzs(): int
    {
        if (! isset($this->memo['cushion'])) {
            $balances = Balances::forUser($this->user);
            $this->memo['cushion'] = (int) $this->cushionAccounts()->sum(fn (Account $a) => $this->inUzs($balances[$a->id] ?? 0, $a->currency));
        }

        return $this->memo['cushion'];
    }

    /** @return Collection<int, Account> */
    public function cushionAccounts(): Collection
    {
        return $this->memo['cushionAccounts'] ??= $this->user->accounts()->whereNull('archived_at')->where('is_cushion', true)->get();
    }

    /** Income expected per month from all paydays, in som; 0 without paydays. */
    public function expectedIncomeUzs(): int
    {
        return $this->memo['expectedIncome'] ??= (int) $this->user->incomeSchedules()->where('active', true)->get()
            ->sum(fn ($s) => $this->inUzs($s->expected_amount, $s->currency));
    }

    /** @return array{income: int, expense: int} totals of a month in som, transfers left out */
    public function monthTotals(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();
        $rows = $this->user->transactions()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $start->copy()->addMonth())
            ->whereIn('type', ['income', 'expense'])
            ->selectRaw('type, SUM(amount_uzs) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return ['income' => (int) ($rows['income'] ?? 0), 'expense' => (int) ($rows['expense'] ?? 0)];
    }

    public function inUzs(int $amount, string $currency): int
    {
        $rate = app(ExchangeRates::class)->rateFor($currency, $this->now);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }

    /** @param  list<int>  $values */
    public static function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
