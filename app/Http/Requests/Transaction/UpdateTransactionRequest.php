<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'             => ['sometimes', 'in:income,expense'],
            'category'         => ['sometimes', 'string', 'max:60'],
            'amount'           => ['sometimes', 'numeric', 'min:0.01'],
            'transaction_date' => ['sometimes', 'date'],
            'note'             => ['nullable', 'string', 'max:300'],
        ];
    }
}
