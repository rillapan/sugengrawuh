<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetSimulationScenarioRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function rules()
    {
        return [
            'device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'scenario' => ['required', 'string', 'in:SUCCESS,OFFLINE,TIMEOUT,REJECTED,RECOVERED'],
        ];
    }

    public function messages()
    {
        return [
            'device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database inventaris.',
            'scenario.in' => 'Skenario harus salah satu dari: SUCCESS, OFFLINE, TIMEOUT, REJECTED, RECOVERED.',
        ];
    }
}
