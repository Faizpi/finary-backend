<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'      => ['required', 'string', 'max:60'],
            'period'        => ['nullable', 'date_format:Y-m'],
            'monthly_limit' => ['required', 'numeric', 'min:1'],
        ];
    }
}
