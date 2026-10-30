<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ExpenseWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'in:'.implode(',', ExpenseCategory::codes())],
            'amount' => ['required', 'integer', 'min:1'],
            'spent_at' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama pengeluaran wajib diisi.',
            'name.max' => 'Nama pengeluaran maksimal 100 karakter.',
            'category.required' => 'Kategori pengeluaran wajib dipilih.',
            'category.in' => 'Kategori pengeluaran tidak valid.',
            'amount.integer' => 'Jumlah pengeluaran harus berupa bilangan bulat rupiah.',
            'amount.min' => 'Jumlah pengeluaran minimal Rp1.',
            'spent_at.date_format' => 'Tanggal pengeluaran harus berformat YYYY-MM-DD.',
            'notes.max' => 'Catatan maksimal 255 karakter.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $spentAt = $this->input('spent_at');
            if (! is_string($spentAt) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $spentAt) !== 1) {
                return;
            }

            try {
                $date = Carbon::createFromFormat('!Y-m-d', $spentAt, 'Asia/Jakarta');
            } catch (\Throwable) {
                return;
            }

            if ($date === false || $date->format('Y-m-d') !== $spentAt) {
                return;
            }

            if ($spentAt > Carbon::now('Asia/Jakarta')->toDateString()) {
                $validator->errors()->add('spent_at', 'Tanggal pengeluaran tidak boleh melewati hari ini WIB.');
            }
        });
    }
}
