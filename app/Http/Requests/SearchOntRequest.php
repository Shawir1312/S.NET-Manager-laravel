<?php

namespace App\Http\Requests;

use App\Support\SerialNumber;
use Illuminate\Foundation\Http\FormRequest;

class SearchOntRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-devices') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['serial_number' => SerialNumber::normalize((string) $this->input('serial_number', ''))]);
    }

    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'min:4', 'max:64', 'regex:'.SerialNumber::PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serial Number wajib diisi.',
            'serial_number.regex' => 'Format Serial Number tidak valid. Gunakan huruf, angka, atau tanda - _ . saja.',
            'serial_number.min' => 'Serial Number minimal 4 karakter.',
        ];
    }
}
