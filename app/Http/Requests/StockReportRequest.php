<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d']];
    }
}
