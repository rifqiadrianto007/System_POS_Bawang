<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['owner', 'kasir'], true);
    }

    public function rules(): array
    {
        return [
            'customer_category' => ['required', 'in:ecer,grosir'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.weight_kg' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,3})(?:\.\d{1,3})?$/'],
            'final_total' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_category.in' => 'Kategori pelanggan harus ecer atau grosir.',
            'items.min' => 'Keranjang tidak boleh kosong.',
            'items.*.weight_kg.regex' => 'Berat harus memiliki maksimal tiga angka desimal.',
        ];
    }
}
