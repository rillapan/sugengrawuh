<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiagnosticPairRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function rules()
    {
        return [
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'transport_mode' => ['nullable', 'string', 'in:fake,real'],
            'password' => ['nullable', 'string', 'max:100'],
            'scenario' => ['nullable', 'string', 'in:SUCCESS,OFFLINE,TIMEOUT,AUTH_REJECTED,INVALID_CREDENTIALS'],
        ];
    }

    public function messages()
    {
        return [
            'device_id.required' => 'ID perangkat wajib diisi.',
            'device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database registry.',
        ];
    }
}
