<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->user()->id;
        $ownAccount = Rule::exists('accounts', 'id')->where('user_id', $userId);

        return [
            'type' => ['required', Rule::in(Transaction::TYPES)],
            'account_id' => ['required', 'integer', $ownAccount],
            'to_account_id' => ['exclude_unless:type,transfer', 'required', 'integer', 'different:account_id', $ownAccount],
            // Up to a trillion som or ten billion dollars: far beyond real use, far below bigint.
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'to_amount' => ['exclude_unless:type,transfer', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'category_id' => [
                'exclude_if:type,transfer', 'required', 'integer',
                Rule::exists('categories', 'id')->where('user_id', $userId)->where('kind', $this->input('type')),
            ],
            'note' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'is_one_off' => ['sometimes', 'boolean'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'source' => ['sometimes', Rule::in(Transaction::SOURCES)],
        ];
    }
}
