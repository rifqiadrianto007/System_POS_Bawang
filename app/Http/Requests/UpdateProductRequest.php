<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')->ignore($this->route('product')),
            ],
            'price_retail' => ['required', 'integer', 'min:0'],
            'price_3kg' => ['required', 'integer', 'min:0'],
            'price_5kg' => ['required', 'integer', 'min:0'],
        ];
    }
}
