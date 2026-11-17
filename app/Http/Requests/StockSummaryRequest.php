<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StockSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('from') !== $this->filled('to')) {
                $validator->errors()->add('to', 'Tanggal awal dan akhir harus diisi bersama.');
            } elseif ($this->filled('from') && $this->input('to') < $this->input('from')) {
                $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
            }
        });
    }
}
