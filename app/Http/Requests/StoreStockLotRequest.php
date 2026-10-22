<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'clean_weight_kg' => ['required', 'decimal:3', 'gt:0', 'max:9999.999'],
            'cleaned_at' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
