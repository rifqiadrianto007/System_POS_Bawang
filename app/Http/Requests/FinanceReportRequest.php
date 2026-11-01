<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['daily', 'monthly'])],
            'date' => ['required_if:period,daily', 'date_format:Y-m-d'],
            'month' => ['required_if:period,monthly', 'date_format:Y-m'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPeriod = $this->filled('period');
            $hasRange = $this->filled('from') || $this->filled('to');
            if ($hasPeriod && $hasRange) {
                $validator->errors()->add('from', 'Rentang tanggal tidak boleh digabung dengan period.');
                return;
            }
            if ($hasRange && ($this->filled('date') || $this->filled('month'))) {
                $validator->errors()->add('from', 'Gunakan satu jenis parameter periode laporan.');
            }
            if (!$hasPeriod && !$hasRange && ($this->filled('date') || $this->filled('month'))) {
                $validator->errors()->add('period', 'Parameter period wajib untuk date atau month.');
            }
            if ($hasPeriod && $this->input('period') === 'daily' && $this->filled('month')) {
                $validator->errors()->add('month', 'Parameter month tidak sesuai dengan period harian.');
            }
            if ($hasPeriod && $this->input('period') === 'monthly' && $this->filled('date')) {
                $validator->errors()->add('date', 'Parameter date tidak sesuai dengan period bulanan.');
            }
            if ($this->filled('from') && !$this->filled('to')) {
                $validator->errors()->add('to', 'Tanggal akhir wajib diisi bersama tanggal awal.');
            }
            if ($this->filled('to') && !$this->filled('from')) {
                $validator->errors()->add('from', 'Tanggal awal wajib diisi bersama tanggal akhir.');
            }
            if ($this->filled('from') && $this->filled('to')
                && $this->input('from') <= $this->input('to')) {
                $days = \Carbon\Carbon::createFromFormat('!Y-m-d', $this->input('from'))->diffInDays(
                    \Carbon\Carbon::createFromFormat('!Y-m-d', $this->input('to'))
                ) + 1;
                if ($days > 366) {
                    $validator->errors()->add('to', 'Rentang laporan maksimal 366 hari.');
                }
            }
            if ($this->filled('from') && $this->filled('to') && $this->input('to') < $this->input('from')) {
                $validator->errors()->add('to', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
            }
        });
    }
}
