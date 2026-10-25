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
            'final_total' => ['required', 'integer', 'min:1'],
            'amount_paid' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_total.required' => 'Total akhir wajib diisi.',
            'final_total.integer' => 'Total akhir harus berupa bilangan bulat rupiah.',
            'final_total.min' => 'Total akhir minimal Rp1.',
            'amount_paid.required' => 'Uang yang dibayarkan wajib diisi.',
            'amount_paid.integer' => 'Uang yang dibayarkan harus berupa bilangan bulat rupiah.',
            'amount_paid.min' => 'Uang yang dibayarkan minimal Rp1.',
            'amount_paid.max' => 'Uang yang dibayarkan tidak boleh melebihi Rp1.000.000.000.',
        ];
    }
}
