<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiagnosticProbeRequest extends FormRequest
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
            'scenario' => ['nullable', 'string', 'in:SUCCESS,OFFLINE,TIMEOUT,AUTH_REJECTED,MALFORMED'],
        ];
    }

    public function messages()
    {
        return [
            'device_id.required' => 'ID perangkat wajib diisi.',
            'device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database registry.',
            'transport_mode.in' => 'Mode transport harus berupa fake atau real.',
        ];
    }
}
