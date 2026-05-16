<?php

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

class PatchAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'loan_payment'             => ['nullable', 'numeric', 'min:0'],
            'emergency_fund'           => ['nullable', 'numeric', 'min:0'],
            'available_hours_per_week' => ['nullable', 'integer', 'min:0', 'max:168'],
            'skills'                   => ['nullable', 'array', 'max:20'],
            'skills.*'                 => ['string', 'max:40'],
        ];
    }
}
