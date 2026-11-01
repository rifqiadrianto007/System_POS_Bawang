<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'nullable', 'in:open,finalized'],
            'include' => ['sometimes', 'nullable', 'in:lots'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'Halaman harus berupa bilangan bulat.',
            'page.min' => 'Halaman minimal 1.',
            'per_page.integer' => 'Jumlah kulak per halaman harus berupa bilangan bulat.',
            'per_page.min' => 'Jumlah kulak per halaman minimal 1.',
            'from.date_format' => 'Tanggal awal harus berformat YYYY-MM-DD.',
            'to.date_format' => 'Tanggal akhir harus berformat YYYY-MM-DD.',
            'status.in' => 'Status kulak tidak valid.',
            'include.in' => 'Data tambahan yang diminta tidak valid.',
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
            } elseif ($this->validDate($from) && $this->validDate($to)) {
                if ($to < $from) {
                    $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
                } else {
                    $days = Carbon::createFromFormat('!Y-m-d', $from)->diffInDays(
                        Carbon::createFromFormat('!Y-m-d', $to)
                    ) + 1;
                    if ($days > 366) {
                        $validator->errors()->add('to', 'Rentang tanggal maksimal 366 hari.');
                    }
                }
            }
        });
    }

    private function validDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
