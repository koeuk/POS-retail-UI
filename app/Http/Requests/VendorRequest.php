<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_name' => $this->input('contact_name') ?: null,
            'phone' => $this->input('phone') ?: null,
            'email' => $this->input('email') ?: null,
            'address' => $this->input('address') ?: null,
            'notes' => $this->input('notes') ?: null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
