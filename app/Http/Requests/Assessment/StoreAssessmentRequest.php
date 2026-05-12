<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monthly_income'  => ['required', 'numeric', 'min:1'],
            'monthly_expense' => ['required', 'numeric', 'min:0'],
            'actual_savings'  => ['required', 'numeric', 'min:0'],
            'budget_goal'     => ['required', 'numeric', 'min:0'],
            'emergency_fund'  => ['required', 'numeric', 'min:0'],
            'loan_payment'    => ['nullable', 'numeric', 'min:0'],
            'classification'  => ['nullable', 'string', 'max:40'],
            'ml_score'        => ['nullable', 'numeric'],
            'ml_explanation'  => ['nullable', 'string', 'max:500'],
        ];
    }
}
