<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['owner', 'kasir'], true);
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'customer_category' => ['required', 'in:ecer,grosir'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.weight_kg' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,3})(?:\.\d{1,3})?$/'],
            'payment_method' => ['required', 'in:cash,qris'],
            'final_total' => ['nullable', 'integer', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
