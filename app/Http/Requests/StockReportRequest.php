<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('from') && !$this->filled('to')) {
                $validator->errors()->add('to', 'Tanggal akhir wajib diisi bersama tanggal awal.');
            } elseif ($this->filled('to') && !$this->filled('from')) {
                $validator->errors()->add('from', 'Tanggal awal wajib diisi bersama tanggal akhir.');
            } elseif ($this->filled('from') && $this->input('to') < $this->input('from')) {
                $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
            }
            if ($this->filled('date') && ($this->filled('from') || $this->filled('to'))) {
                $validator->errors()->add('date', 'Gunakan date atau rentang from dan to.');
            }
        });
    }
}
