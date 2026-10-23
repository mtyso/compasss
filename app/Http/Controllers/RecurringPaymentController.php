<?php

namespace App\Http\Controllers;

use App\Models\RecurringPayment;
use App\Money\PaymentPayer;
use App\Money\SubscriptionFinder;
use App\Support\Money;
use App\Support\MonthDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RecurringPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = $request->user()->recurringPayments()->orderBy('next_due_on')->get();

        return response()->json($payments->map(fn (RecurringPayment $p) => $p->toApi())->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        // Unless told otherwise, the first due date is the next such day, today included.
        $data['next_due_on'] ??= MonthDay::next($data['day_of_month'], now())->toDateString();

        return response()->json($request->user()->recurringPayments()->create($data)->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $payment = $request->user()->recurringPayments()->findOrFail($id);
        $data = $this->validated($request, false);

        // A new day of month moves the pending due date within its month.
        if (isset($data['day_of_month']) && ! isset($data['next_due_on'])) {
            $due = $payment->dueDate();
            $data['next_due_on'] = MonthDay::in($due->year, $due->month, $data['day_of_month'])->toDateString();
        }
        $payment->update($data);

        return response()->json($payment->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->recurringPayments()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** «Оплачено» in one tap: records the expense and moves the due date a month ahead. */
    public function pay(Request $request, PaymentPayer $payer, int $id): JsonResponse
    {
        $user = $request->user();
        $payment = $user->recurringPayments()->findOrFail($id);

        $data = $request->validate([
            'account_id' => [$payment->account_id ? 'sometimes' : 'required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $transaction = $payer->pay(
            $user,
            $payment,
            isset($data['account_id']) ? $user->accounts()->find($data['account_id']) : null,
            $data['amount'] ?? null,
            isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'], config('app.timezone')) : null,
        );

        return response()->json(['payment' => $payment->toApi(), 'transaction' => $transaction->toApi()]);
    }

    /** «Пропустить в этот раз»: nothing to pay this month (a paused subscription), so the next date comes without an expense. */
    public function skip(Request $request, int $id): JsonResponse
    {
        $payment = $request->user()->recurringPayments()->findOrFail($id);
        $payment->advance();

        return response()->json($payment->toApi());
    }

    /** Spending that repeats every month but is not a recurring payment yet (App\Money\SubscriptionFinder). */
    public function suggestions(Request $request, SubscriptionFinder $finder): JsonResponse
    {
        return response()->json($finder->find($request->user(), now()));
    }

    /** «Не то»: the suggestion stops coming back, here and in the advice. */
    public function dismissSuggestion(Request $request): Response
    {
        $key = $request->validate(['key' => ['required', 'string', 'max:120']])['key'];
        $user = $request->user();
        $dismissed = array_values(array_unique([...(array) $user->setting('dismissed_subscriptions', []), $key]));
        $user->update(['settings' => [...($user->settings ?? []), 'dismissed_subscriptions' => $dismissed]]);
        $user->insights()->where(['rule' => 'subscription_found', 'subject' => $key])->whereIn('status', ['new', 'seen'])->update(['status' => 'dismissed']);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $userId = $request->user()->id;

        return $request->validate([
            'name' => [$required, 'string', 'max:60'],
            'amount' => [$required, 'integer', 'min:1', 'max:1000000000000'],
            'currency' => [$required, Rule::in(array_keys(Money::CURRENCIES))],
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)->where('kind', 'expense')],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'day_of_month' => [$required, 'integer', 'between:1,31'],
            'next_due_on' => ['sometimes', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
