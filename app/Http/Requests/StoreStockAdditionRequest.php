<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:purchases,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'weight_kg' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,3})\.\d{3}$/', 'not_in:0.000'],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_kg.regex' => 'Berat harus berupa kilogram dengan tiga angka desimal.',
            'weight_kg.not_in' => 'Berat Tambah harus lebih besar dari 0 kg.',
        ];
    }
}
