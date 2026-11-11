<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:products,name'],
            'jenis' => ['required', 'string', 'in:merah,putih'],
            'price_retail' => ['required', 'integer', 'min:0'],
            'price_5kg' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis bawang wajib dipilih.',
            'jenis.in' => 'Jenis bawang harus merah atau putih.',
        ];
    }
}
