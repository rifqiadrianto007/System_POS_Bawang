<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExpenseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'category' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ExpenseCategory::codes())],
            'q' => ['sometimes', 'nullable', 'string', 'max:50'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format' => 'Tanggal awal harus berformat YYYY-MM-DD.',
            'to.date_format' => 'Tanggal akhir harus berformat YYYY-MM-DD.',
            'category.in' => 'Kategori pengeluaran tidak valid.',
            'q.max' => 'Pencarian maksimal 50 karakter.',
            'page.integer' => 'Halaman harus berupa bilangan bulat.',
            'page.min' => 'Halaman minimal 1.',
            'per_page.integer' => 'Jumlah baris per halaman harus berupa bilangan bulat.',
            'per_page.min' => 'Jumlah baris per halaman minimal 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');
            if ($this->filled('from') && ! $this->filled('to')) {
                $validator->errors()->add('to', 'Tanggal akhir wajib diisi bersama tanggal awal.');
            } elseif ($this->filled('to') && ! $this->filled('from')) {
                $validator->errors()->add('from', 'Tanggal awal wajib diisi bersama tanggal akhir.');
            } elseif ($this->isValidDate($from) && $this->isValidDate($to)) {
                if ($to < $from) {
                    $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
                } else {
                    $days = \Carbon\Carbon::createFromFormat('!Y-m-d', $from)->diffInDays(
                        \Carbon\Carbon::createFromFormat('!Y-m-d', $to)
                    ) + 1;
                    if ($days > 366) {
                        $validator->errors()->add('to', 'Rentang tanggal maksimal 366 hari.');
                    }
                }
            }
        });
    }

    private function isValidDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
