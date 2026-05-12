<?php

namespace App\Http\Requests\Recommendation;

use Illuminate\Foundation\Http\FormRequest;

class SideHustleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skills'                   => ['nullable', 'array'],
            'skills.*'                 => ['string', 'max:40'],
            'experience_level'         => ['nullable', 'string', 'max:40'],
            'interest_category'        => ['nullable', 'string', 'max:80'],
            'available_hours_per_week' => ['nullable', 'integer', 'min:0', 'max:168'],
            'classification'           => ['nullable', 'in:survival,stable,growth'],
        ];
    }
}
