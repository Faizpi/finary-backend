<?php

namespace App\Http\Requests\Forum;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'  => ['required', 'string', 'max:140'],
            'body'   => ['required', 'string', 'max:1500'],
            'tags'   => ['nullable', 'array'],
            'tags.*' => ['string', 'max:30'],
        ];
    }
}
