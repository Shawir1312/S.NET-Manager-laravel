<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGenieAcsSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'nbi_url' => ['required', 'url:http,https', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'server_id' => ['nullable', 'integer', 'exists:genieacs_settings,id'],
            'is_active' => ['sometimes', 'boolean'],
            'cwmp_url' => ['nullable', 'url:http,https', 'max:255'],
            'username' => ['nullable', 'string', 'max:191'],
            // Kosong = tetap pakai password tersimpan.
            'password' => ['nullable', 'string', 'max:255'],
            'clear_credentials' => ['sometimes', 'boolean'],
            'connect_timeout' => ['required', 'integer', 'between:1,60'],
            'request_timeout' => ['required', 'integer', 'between:5,300'],
            'connection_request_timeout' => ['required', 'integer', 'between:1,120'],
            'verify_tls' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nbi_url' => 'GenieACS NBI URL',
            'cwmp_url' => 'GenieACS CWMP URL',
            'connect_timeout' => 'Connection Timeout',
            'request_timeout' => 'Request Timeout',
            'connection_request_timeout' => 'Connection Request Timeout',
        ];
    }
}
