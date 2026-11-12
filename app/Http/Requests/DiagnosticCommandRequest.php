<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiagnosticCommandRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function rules()
    {
        return [
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'command' => ['required', 'string', 'max:50'],
            'transport_mode' => ['nullable', 'string', 'in:fake,real'],
            'scenario' => ['nullable', 'string', 'max:50'],
            'fake_scenario' => ['nullable', 'string', 'max:50'],
            'approval_token' => ['nullable', 'string', 'max:128'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    public function messages()
    {
        return [
            'device_id.required' => 'ID perangkat wajib diisi.',
            'device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database registry.',
            'command.required' => 'Perintah kontrol wajib diisi.',
        ];
    }
}
