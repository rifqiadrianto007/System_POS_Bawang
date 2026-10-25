<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'counted_weight_kg' => ['required', 'decimal:3', 'min:0', 'max:9999.999'],
            'counted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
