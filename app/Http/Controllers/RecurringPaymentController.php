<?php

namespace App\Http\Controllers;

use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Money\TransactionWriter;
use App\Support\Money;
use App\Support\MonthDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    public function pay(Request $request, TransactionWriter $writer, int $id): JsonResponse
    {
        $user = $request->user();
        $payment = $user->recurringPayments()->findOrFail($id);

        $data = $request->validate([
            'account_id' => [$payment->account_id ? 'sometimes' : 'required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($writer, $user, $payment, $data) {
            $transaction = new Transaction(['recurring_payment_id' => $payment->id]);
            $writer->save($user, $transaction, [
                'type' => 'expense',
                'account_id' => $data['account_id'] ?? $payment->account_id,
                'amount' => $data['amount'] ?? $this->amountIn($payment, $user->accounts()->find($data['account_id'] ?? $payment->account_id)?->currency),
                'category_id' => $payment->category_id,
                'note' => $payment->name,
                'occurred_at' => $data['occurred_at'] ?? now()->toDateTimeString(),
            ]);

            $payment->update(['next_due_on' => MonthDay::nextMonth($payment->dueDate(), $payment->day_of_month)->toDateString()]);

            return $transaction;
        });

        return response()->json(['payment' => $payment->toApi(), 'transaction' => $transaction->toApi()]);
    }

    /** The payment's amount for an account in the same currency; otherwise the app must send one. */
    private function amountIn(RecurringPayment $payment, ?string $accountCurrency): int
    {
        if ($accountCurrency !== null && $accountCurrency !== $payment->currency) {
            throw ValidationException::withMessages(['amount' => __('money.pay_amount_required')]);
        }

        return $payment->amount;
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
