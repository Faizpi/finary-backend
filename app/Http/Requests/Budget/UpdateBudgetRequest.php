<?php

namespace App\Http\Requests\Budget;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'      => ['sometimes', 'string', 'max:60'],
            'period'        => ['sometimes', 'date_format:Y-m'],
            'monthly_limit' => ['sometimes', 'numeric', 'min:1'],
        ];
    }
}
