<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:products,name'],
            'price_retail' => ['required', 'integer', 'min:0'],
            'price_3kg' => ['required', 'integer', 'min:0'],
            'price_5kg' => ['required', 'integer', 'min:0'],
        ];
    }
}
