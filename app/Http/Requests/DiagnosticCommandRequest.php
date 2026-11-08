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
            'scenario' => ['nullable', 'string', 'in:SUCCESS,OFFLINE,TIMEOUT,REJECTED,AUTH_REQUIRED,INVALID_JSON'],
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
