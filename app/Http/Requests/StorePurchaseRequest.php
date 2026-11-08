<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchased_at' => ['required', 'date_format:Y-m-d'],
            'jenis' => ['required', 'in:merah,putih'],
            'gross_weight_kg' => ['required', 'decimal:3', 'gt:0', 'max:9999.999'],
            'price_per_kg' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('purchased_at') > now('Asia/Jakarta')->toDateString()) {
                $validator->errors()->add('purchased_at', 'Tanggal kulak tidak boleh melewati hari ini.');
            }
        }];
    }
}
