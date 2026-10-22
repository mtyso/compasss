<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Money\Debts;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** «Долги» (docs/PLAN.md, D29): what the user owes and what is owed to them, and the moves between them. */
class DebtController extends Controller
{
    public function __construct(private Debts $debts) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->debts->overview($request->user(), now()));
    }

    /** «Дал в долг»: to someone new (`name`) or more to someone who already owes (`lent_account_id`). */
    public function lend(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required_without:lent_account_id', 'nullable', 'string', 'max:60'],
            'lent_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->where('type', 'lent')],
            'account_id' => ['required', 'integer', $this->ordinaryAccount($request)],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'to_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'occurred_at' => ['sometimes', 'date'],
        ]);
        [$lent, $transaction] = $this->debts->lend($user, $user->accounts()->findOrFail($data['account_id']), $data, now());

        return response()->json(['account_id' => $lent->id, 'transaction' => $transaction->toApi(), ...$this->debts->overview($user, now())], 201);
    }

    /** «Взял в долг»: the money came to `account_id`, or it is an old debt (no account, a currency instead). */
    public function borrow(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'account_id' => ['sometimes', 'nullable', 'integer', $this->ordinaryAccount($request)],
            'currency' => ['required_without:account_id', 'nullable', Rule::in(array_keys(Money::CURRENCIES))],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'monthly_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            'rate' => ['sometimes', 'nullable', 'numeric', 'between:0,200'],
            'occurred_at' => ['sometimes', 'date'],
        ]);
        $to = empty($data['account_id']) ? null : $user->accounts()->findOrFail($data['account_id']);
        [$debt, $goal] = $this->debts->borrow($user, $to, $data, now());

        return response()->json(['account_id' => $debt->id, 'goal_id' => $goal?->id, ...$this->debts->overview($user, now())], 201);
    }

    /** «Внести платёж» on a debt, «Вернул» on money lent; the sum is in the debt's currency. */
    public function settle(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $debt = $user->accounts()->whereIn('type', ['debt', 'lent'])->findOrFail($id);
        $data = $request->validate([
            'account_id' => ['required', 'integer', $this->ordinaryAccount($request)],
            'amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'other_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'occurred_at' => ['sometimes', 'date'],
        ]);
        $transaction = $this->debts->settle($user, $debt, $user->accounts()->findOrFail($data['account_id']), $data, now());

        return response()->json(['transaction' => $transaction->toApi(), ...$this->debts->overview($user, now())]);
    }

    /** The order to pay debts off in, and money beyond the payments for the first one. */
    public function plan(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'method' => ['required', Rule::in(Debts::METHODS)],
            'extra_uzs' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
        ]);
        $goal = $this->debts->plan($user, $data['method'], (int) ($data['extra_uzs'] ?? 0), now());

        return response()->json(['goal_id' => $goal?->id, ...$this->debts->overview($user, now())]);
    }

    /** An account of the user's own, not a debt either way. */
    private function ordinaryAccount(Request $request): Exists
    {
        return Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)->whereNull('archived_at')->whereNotIn('type', ['debt', 'lent']);
    }
}
