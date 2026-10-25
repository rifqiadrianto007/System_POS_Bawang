<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['daily', 'monthly'])],
            'date' => ['required_if:period,daily', 'date_format:Y-m-d'],
            'month' => ['required_if:period,monthly', 'date_format:Y-m'],
        ];
    }
}
