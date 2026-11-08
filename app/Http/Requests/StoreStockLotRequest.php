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
            'weight_kg' => ['required', 'decimal:3', 'gt:0', 'max:9999.999'],
            'sorted_at' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('sorted_at') > now('Asia/Jakarta')->toDateString()) {
                $validator->errors()->add('sorted_at', 'Tanggal sortir tidak boleh melewati hari ini.');
            }
        }];
    }
}
