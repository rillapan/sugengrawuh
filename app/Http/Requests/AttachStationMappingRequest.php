<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachStationMappingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'physical_device_id' => ['required', 'integer', 'exists:physical_devices,id'],
            'mapping_role' => ['required', 'string', 'in:DISPLAY_TV,LIGHT_RELAY'],
            'attach_notes' => ['nullable', 'string', 'max:500'],
            'detach_notes' => ['nullable', 'string', 'max:500'],
            'replace' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'physical_device_id.exists' => 'Perangkat fisik tidak ditemukan dalam database.',
            'mapping_role.in' => 'Peran mapping harus DISPLAY_TV atau LIGHT_RELAY.',
        ];
    }
}
