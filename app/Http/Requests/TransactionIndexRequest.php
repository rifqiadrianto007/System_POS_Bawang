<?php

namespace App\Http\Requests;

use App\Support\CashierTransactionWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransactionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['owner', 'kasir'], true);
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'in:today,yesterday'],
            'q' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format' => 'Tanggal awal harus berformat YYYY-MM-DD.',
            'to.date_format' => 'Tanggal akhir harus berformat YYYY-MM-DD.',
            'period.in' => 'Periode hanya boleh today atau yesterday.',
            'q.string' => 'Pencarian nomor transaksi harus berupa teks.',
            'q.max' => 'Pencarian nomor transaksi maksimal 30 karakter.',
            'page.integer' => 'Halaman harus berupa bilangan bulat.',
            'page.min' => 'Halaman minimal 1.',
            'per_page.integer' => 'Jumlah baris per halaman harus berupa bilangan bulat.',
            'per_page.min' => 'Jumlah baris per halaman minimal 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->exists('period') && ($this->exists('from') || $this->exists('to'))) {
                $validator->errors()->add('period', 'Parameter period tidak boleh digunakan bersama from atau to.');
            }

            $from = $this->input('from');
            $to = $this->input('to');
            if (is_string($from) && is_string($to) && $to < $from) {
                $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
            }

            if ($this->user()?->role !== 'kasir') {
                return;
            }

            $earliest = CashierTransactionWindow::earliestDate();
            $latest = CashierTransactionWindow::latestDate();
            if (($from !== null && ($from < $earliest || $from > $latest))
                || ($to !== null && ($to < $earliest || $to > $latest))) {
                $field = $from !== null && ($from < $earliest || $from > $latest) ? 'from' : 'to';
                $validator->errors()->add($field, 'Kasir hanya dapat melihat transaksi hari ini dan kemarin.');
            }
        });
    }
}
