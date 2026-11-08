<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulateCommandRequest extends FormRequest
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
        ];
    }

    public function messages()
    {
        return [
            'device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database inventaris.',
            'command.required' => 'Perintah simulasi wajib diisi.',
        ];
    }
}
