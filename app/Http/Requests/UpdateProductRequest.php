<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'owner';
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')->ignore($this->route('product')),
            ],
            'jenis' => ['required', 'string', Rule::in([$this->route('product')->jenis])],
            'price_retail' => ['required', 'integer', 'min:0'],
            'price_5kg' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis.required' => 'Jenis bawang wajib dipilih.',
            'jenis.in' => 'Jenis bawang produk tidak dapat diubah.',
        ];
    }
}
