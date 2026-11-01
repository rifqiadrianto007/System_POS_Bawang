<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', 'exists:suppliers,id'],
            'purchased_at' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'gross_weight_kg' => ['sometimes', 'required', 'decimal:3', 'gt:0', 'max:9999.999'],
            'price_per_kg' => ['sometimes', 'required', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pemasok wajib dipilih.',
            'supplier_id.exists' => 'Pemasok tidak ditemukan.',
            'purchased_at.date_format' => 'Tanggal kulak harus berformat YYYY-MM-DD.',
            'gross_weight_kg.decimal' => 'Berat kotor harus menggunakan maksimal tiga angka desimal.',
            'gross_weight_kg.gt' => 'Berat kotor harus lebih dari 0 kg.',
            'price_per_kg.integer' => 'Harga per kilogram harus berupa bilangan bulat rupiah.',
            'price_per_kg.min' => 'Harga per kilogram tidak boleh negatif.',
        ];
    }
}
