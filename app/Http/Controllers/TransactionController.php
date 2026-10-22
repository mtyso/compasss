<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\Transaction;
use App\Money\TransactionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(Transaction::TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $user->transactions()->orderByDesc('occurred_at')->orderByDesc('id');

        if (isset($filters['month'])) {
            $start = Carbon::createFromFormat('Y-m', $filters['month'], config('app.timezone'))->startOfMonth();
            $query->where('occurred_at', '>=', $start)->where('occurred_at', '<', $start->copy()->addMonth());
        }
        if (isset($filters['account_id'])) {
            $id = $filters['account_id'];
            $query->where(fn ($q) => $q->where('account_id', $id)->orWhere('to_account_id', $id));
        }
        if (isset($filters['category_id'])) {
            // A main category includes its subcategories.
            $ids = $user->categories()->where('id', $filters['category_id'])->orWhere('parent_id', $filters['category_id'])->pluck('id');
            $query->whereIn('category_id', $ids);
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['q'])) {
            $query->where('note', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
        }

        $page = $query->cursorPaginate(100);

        return response()->json([
            'data' => collect($page->items())->map->toApi()->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(TransactionRequest $request, TransactionWriter $writer): JsonResponse
    {
        $transaction = $writer->save($request->user(), new Transaction, $request->validated());

        return response()->json($transaction->toApi(), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($request->user()->transactions()->findOrFail($id)->toApi());
    }

    public function update(TransactionRequest $request, TransactionWriter $writer, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        return response()->json($writer->save($request->user(), $transaction, $request->validated())->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->transactions()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $transaction = $request->user()->transactions()->onlyTrashed()->findOrFail($id);
        $transaction->restore();

        return response()->json($transaction->toApi());
    }
}
