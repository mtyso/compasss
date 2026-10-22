<?php

namespace App\Http\Controllers;

use App\Goals\GoalContributor;
use App\Goals\GoalPlanner;
use App\Goals\GoalSpeed;
use App\Goals\Installment;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Money\Balances;
use App\Money\TransactionWriter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Goals (docs/PLAN.md, 2.6): the list with this period's plan, one goal in full, «Отложить» and «Купил». */
class GoalController extends Controller
{
    public function __construct(private GoalPlanner $planner) {}

    /** Goals not archived with their plan; `?archived=1` adds the archived ones. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->planner->plan($user, now())->toApi();
        if ($request->boolean('archived')) {
            $data['archived'] = $user->goals()->whereNotNull('archived_at')->latest('archived_at')->get()
                ->map(fn (Goal $g) => ['id' => $g->id, 'type' => $g->type, 'title' => $g->title, 'icon' => $g->icon, 'archived' => true])->values();
        }

        return response()->json($data);
    }

    /** One goal with its steps, habits, contribution history and what could make it faster. */
    public function show(Request $request, GoalSpeed $speed, int $id): JsonResponse
    {
        $user = $request->user();
        $goal = $user->goals()->findOrFail($id);
        $now = now();
        $plan = $this->planner->plan($user, $now);
        $state = $plan->goal($goal->id) ?? $this->planner->state($goal, $now);
        $today = $now->copy()->startOfDay();

        return response()->json([
            'goal' => $state->toApi(),
            'is_main' => $plan->main()?->goal->id === $goal->id,
            'steps' => $goal->tasks()->orderByRaw('done_at is not null')->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id')->get()
                ->map(fn (Task $t) => $t->toApi($today))->values(),
            'habits' => $goal->habits()->whereNull('archived_at')->orderBy('sort')->get()
                ->map(fn (Habit $h) => $h->toApi($today))->values(),
            'history' => $goal->account_id ? $this->history($goal, $today) : [],
            'checkins' => $goal->checkins()->orderByDesc('date')->limit(60)->get()
                ->map(fn ($c) => ['date' => substr((string) $c->date, 0, 10), 'value' => (float) $c->value])->values(),
            'speed' => $goal->hasMoney() ? $speed->for($user, $plan, $state, $now) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, null);

        $goal = DB::transaction(function () use ($user, $data) {
            $account = $this->accountFor($user, $data);
            $balance = $account ? (Balances::forUser($user)[$account->id] ?? 0) : 0;
            if ($account && $data['type'] === 'debt') {
                $data['target_amount'] ??= max(0, -$balance);
            }

            return $user->goals()->create([
                ...collect($data)->except(['cushion'])->all(),
                'account_id' => $account?->id,
                'currency' => $account?->currency ?? ($data['currency'] ?? null),
                // The «в графике» line starts from what the goal already has.
                'start_amount' => match ($data['type']) {
                    'save', 'buy' => max(0, $balance),
                    'debt' => max(0, (int) $data['target_amount'] + min(0, $balance)),
                    default => 0,
                },
                'started_on' => now()->toDateString(),
                'sort' => (int) $user->goals()->max('sort') + 1,
            ]);
        });

        return response()->json($this->planner->state($goal->fresh(), now())->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $goal = $request->user()->goals()->findOrFail($id);
        $data = $this->validated($request, $goal);

        foreach (['archived' => 'archived_at', 'done' => 'done_at'] as $flag => $column) {
            if (array_key_exists($flag, $data)) {
                $data[$column] = $data[$flag] ? ($goal->{$column} ?? now()) : null;
                unset($data[$flag]);
            }
        }
        $goal->update($data);

        return response()->json($this->planner->state($goal->fresh(), now())->toApi());
    }

    /** The money stays where it is: a jar with history is kept, an unused one goes with the goal. */
    public function destroy(Request $request, int $id): Response
    {
        $goal = $request->user()->goals()->findOrFail($id);
        $account = $goal->account;
        DB::transaction(function () use ($goal, $account) {
            $goal->delete();
            if ($account && ! $account->hasTransactions() && $account->opening_balance === 0) {
                $account->delete();
            }
        });

        return response()->noContent();
    }

    /** New order of the goals; the first one becomes the main goal. */
    public function order(Request $request): Response
    {
        $user = $request->user();
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer', 'distinct', Rule::exists('goals', 'id')->where('user_id', $user->id)],
        ])['ids'];
        DB::transaction(function () use ($user, $ids) {
            foreach ($ids as $index => $id) {
                $user->goals()->whereKey($id)->update(['sort' => $index + 1]);
            }
        });

        return response()->noContent();
    }

    /** «Отложить»: amounts in each goal's currency, all from one account. */
    public function contribute(Request $request, GoalContributor $contributor): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.goal_id' => ['required', 'integer', 'distinct', Rule::exists('goals', 'id')->where('user_id', $user->id)],
            'items.*.amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ]);
        $from = $user->accounts()->findOrFail($data['account_id']);
        $goals = $user->goals()->whereIn('id', array_column($data['items'], 'goal_id'))->get()->keyBy('id');
        $items = array_map(fn (array $i) => ['goal' => $goals[$i['goal_id']], 'amount' => (int) $i['amount']], $data['items']);

        $transactions = $contributor->contribute($user, $from, $items, now());

        return response()->json([
            'transactions' => array_map(fn (Transaction $t) => $t->toApi(), $transactions),
            'goals' => $this->planner->plan($user, now())->toApi(),
        ]);
    }

    /**
     * «Купил»: the purchase is paid from the jar as a one-off expense, and the goal is done. On installment
     * the rest of the price is spent from a new debt account (the overpayment too, as interest), and a debt
     * goal takes the purchase's place in the list, so the payments get their own plan.
     */
    public function buy(Request $request, TransactionWriter $writer, int $id): JsonResponse
    {
        $user = $request->user();
        $goal = $user->goals()->findOrFail($id);
        if ($goal->type !== 'buy' || $goal->account_id === null) {
            throw ValidationException::withMessages(['goal' => __('goals.not_buy')]);
        }
        $data = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $user->id)->where('kind', 'expense')],
            'installment' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'integer', 'min:1', 'max:1000000000000'],
            'loan_months' => ['sometimes', 'integer', 'between:1,120'],
            'rate' => ['sometimes', 'nullable', 'numeric', 'between:0,200'],
        ]);
        $paid = $data['amount'] ?? $goal->target_amount;
        $installment = $data['installment'] ?? $goal->onInstallment();
        if ($installment) {
            $price = $data['price'] ?? $goal->price;
            $months = $data['loan_months'] ?? $goal->loan_months;
            $rate = array_key_exists('rate', $data) ? $data['rate'] : $goal->rate;
            if ($months === null) {
                throw ValidationException::withMessages(['loan_months' => __('goals.loan_months_required')]);
            }
            if ($price === null || $price <= $paid) {
                throw ValidationException::withMessages(['price' => __('goals.no_loan')]);
            }
        }

        [$transaction, $debt] = DB::transaction(function () use ($writer, $user, $goal, $data, $paid, $installment) {
            $expense = fn (int $accountId, int $amount, int $categoryId, string $note) => $writer->save($user, new Transaction, [
                'type' => 'expense',
                'account_id' => $accountId,
                'amount' => $amount,
                'category_id' => $categoryId,
                'note' => $note,
                'occurred_at' => now()->toDateTimeString(),
                'is_one_off' => true,
            ]);
            $transaction = $expense($goal->account_id, $paid, $data['category_id'], __('goals.purchase_note', ['goal' => $goal->title]));
            $goal->update(['done_at' => now()]);

            return [$transaction, $installment ? $this->installmentDebt($user, $goal, $data, $paid, $expense) : null];
        });

        return response()->json([
            'goal' => $this->planner->state($goal->fresh(), now())->toApi(),
            'transaction' => $transaction->toApi(),
            'debt' => $debt ? $this->planner->state($debt, now())->toApi() : null,
        ]);
    }

    /**
     * The installment after a purchase: a debt account that paid the rest of the price, and a debt goal with
     * the monthly payment as its plan. The debt holds all that is owed, interest included, so it is gone
     * after the last payment.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(int, int, int, string): Transaction  $expense
     */
    private function installmentDebt(User $user, Goal $goal, array $data, int $paid, callable $expense): Goal
    {
        $currency = $goal->currency ?? 'UZS';
        $price = $data['price'] ?? $goal->price;
        $months = $data['loan_months'] ?? $goal->loan_months;
        $rate = array_key_exists('rate', $data) ? $data['rate'] : $goal->rate;
        $loan = $price - $paid;
        $repay = Installment::repay($loan, $months, $rate === null ? null : (float) $rate, $currency);
        $title = __('goals.installment_name', ['goal' => $goal->title]);

        $account = $user->accounts()->create([
            'name' => mb_substr($title, 0, 60),
            'type' => 'debt',
            'currency' => $currency,
            'opening_balance' => 0,
            'spendable' => false,
            'is_cushion' => false,
            'sort' => (int) $user->accounts()->max('sort') + 1,
        ]);
        $expense($account->id, $loan, $data['category_id'], __('goals.purchase_note', ['goal' => $goal->title]));
        if ($repay['total'] > $loan) {
            $interest = $user->categories()->where('system_key', 'finance_interest')->value('id') ?? $data['category_id'];
            $expense($account->id, $repay['total'] - $loan, $interest, __('goals.interest_note', ['goal' => $goal->title]));
        }

        return $user->goals()->create([
            'type' => 'debt',
            'title' => mb_substr($title, 0, 80),
            'icon' => $goal->icon,
            'target_amount' => $repay['total'],
            'currency' => $currency,
            'account_id' => $account->id,
            'start_amount' => 0,
            'deadline' => now()->addMonthsNoOverflow($months)->toDateString(),
            'monthly_amount' => $repay['payment'],
            'sort' => $goal->sort,
            'started_on' => now()->toDateString(),
        ]);
    }

    /** A measurement of a goal without money on a day; an empty value removes it. */
    public function checkin(Request $request, int $id, string $date): JsonResponse
    {
        $goal = $request->user()->goals()->findOrFail($id);
        $value = $request->validate(['value' => ['present', 'nullable', 'numeric', 'between:-1000000000,1000000000']])['value'];
        $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        if ($day === null || $day->format('Y-m-d') !== $date || $day->gt(now())) {
            throw ValidationException::withMessages(['date' => __('things.log_date')]);
        }

        if ($value === null) {
            $goal->checkins()->where('date', $date)->delete();
        } else {
            $goal->checkins()->updateOrCreate(['date' => $date], ['value' => $value, 'user_id' => $goal->user_id]);
        }

        return response()->json($this->planner->state($goal->fresh(), now())->toApi());
    }

    /**
     * Net contributions by month for the last six months, in the goal's currency.
     *
     * @return list<array{month: string, amount: int}>
     */
    private function history(Goal $goal, Carbon $today): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            if ($start->copy()->endOfMonth()->lt($goal->startDate()->copy()->startOfMonth())) {
                continue;
            }
            $change = $this->planner->netChange($goal->account_id, $start, $start->copy()->addMonth());
            $months[] = ['month' => $start->format('Y-m'), 'amount' => $change];
        }

        return $months;
    }

    /**
     * The goal's account: the one picked, or a new jar (or debt account) named after the goal.
     *
     * @param  array<string, mixed>  $data
     */
    private function accountFor(User $user, array $data): ?Account
    {
        if (! in_array($data['type'], Goal::MONEY_TYPES, true)) {
            return null;
        }
        if (! empty($data['account_id'])) {
            $account = $user->accounts()->findOrFail($data['account_id']);
            $errors = match (true) {
                $user->goals()->where('account_id', $account->id)->whereNull('archived_at')->whereNull('done_at')->exists() => __('goals.account_taken'),
                $data['type'] === 'debt' && $account->type !== 'debt' => __('goals.account_not_debt'),
                $data['type'] !== 'debt' && $account->spendable && ! $account->is_cushion => __('goals.account_spendable'),
                isset($data['currency']) && $data['currency'] !== $account->currency => __('goals.account_currency'),
                default => null,
            };
            if ($errors) {
                throw ValidationException::withMessages(['account_id' => $errors]);
            }

            return $account;
        }
        if (empty($data['currency'])) {
            throw ValidationException::withMessages(['currency' => __('goals.currency_required')]);
        }
        if (empty($data['target_amount'])) {
            throw ValidationException::withMessages(['target_amount' => __('goals.target_required')]);
        }

        $debt = $data['type'] === 'debt';

        return $user->accounts()->create([
            'name' => mb_substr($debt ? $data['title'] : __('goals.jar_name', ['goal' => $data['title']]), 0, 60),
            'type' => $debt ? 'debt' : 'savings',
            'currency' => $data['currency'],
            'opening_balance' => $debt ? -(int) $data['target_amount'] : 0,
            'spendable' => false,
            'is_cushion' => ! $debt && ! empty($data['cushion']),
            'sort' => (int) $user->accounts()->max('sort') + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Goal $goal): array
    {
        $type = $goal?->type ?? $request->input('type');
        $money = in_array($type, Goal::MONEY_TYPES, true);
        $creating = $goal === null;

        $data = $request->validate([
            'type' => $creating ? ['required', Rule::in(Goal::TYPES)] : ['prohibited'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:30'],
            'currency' => $creating && $money ? ['sometimes', Rule::in(array_keys(Money::CURRENCIES))] : ['prohibited'],
            'account_id' => $creating && $money ? ['sometimes', 'nullable', 'integer'] : ['prohibited'],
            'cushion' => $creating && $type === 'save' ? ['sometimes', 'boolean'] : ['prohibited'],
            'target_amount' => $money ? ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'] : ['prohibited'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'monthly_amount' => $money ? ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'] : ['prohibited'],
            'rate' => in_array($type, ['debt', 'buy'], true) ? ['sometimes', 'nullable', 'numeric', 'between:0,200'] : ['prohibited'],
            'price' => $type === 'buy' ? ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'] : ['prohibited'],
            'down_percent' => $type === 'buy' ? ['sometimes', 'nullable', 'integer', 'between:1,90'] : ['prohibited'],
            'loan_months' => $type === 'buy' ? ['sometimes', 'nullable', 'integer', 'between:1,120'] : ['prohibited'],
            'unit' => $money ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:20'],
            'start_value' => $money ? ['prohibited'] : ['sometimes', 'nullable', 'numeric', 'between:-1000000000,1000000000'],
            'target_value' => $money ? ['prohibited'] : ['sometimes', 'nullable', 'numeric', 'between:-1000000000,1000000000'],
            'archived' => $creating ? ['prohibited'] : ['sometimes', 'boolean'],
            'done' => $creating ? ['prohibited'] : ['sometimes', 'boolean'],
        ]);

        // On installment the goal collects the down payment: its sum follows the price and the share
        if (array_key_exists('price', $data) || array_key_exists('down_percent', $data)) {
            $price = array_key_exists('price', $data) ? $data['price'] : $goal?->price;
            $percent = array_key_exists('down_percent', $data) ? $data['down_percent'] : $goal?->down_percent;
            if ($percent !== null && $price === null) {
                throw ValidationException::withMessages(['price' => __('goals.price_required')]);
            }
            if ($price !== null) {
                $currency = $data['currency'] ?? $goal?->currency
                    ?? (empty($data['account_id']) ? null : $request->user()->accounts()->find($data['account_id'])?->currency) ?? 'UZS';
                $data['target_amount'] = $percent === null ? $price : Installment::downPayment($price, $percent, $currency);
            }
        }
        if ($creating && in_array($type, ['save', 'buy'], true) && empty($data['target_amount'])) {
            throw ValidationException::withMessages(['target_amount' => __('goals.target_required')]);
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }
}
