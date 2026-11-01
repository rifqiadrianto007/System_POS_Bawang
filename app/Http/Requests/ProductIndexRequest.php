<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['owner', 'kasir'], true);
    }

    public function rules(): array
    {
        return ['active' => ['sometimes', 'required', 'in:0,1']];
    }

    public function messages(): array
    {
        return ['active.in' => 'Filter aktif harus bernilai 1 atau 0.'];
    }
}
