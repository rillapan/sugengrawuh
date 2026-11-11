<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePhysicalDeviceRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'category' => ['sometimes', 'required', 'string', 'in:SMART_TV,SMART_RELAY,GAME_CONSOLE'],
            'brand' => ['sometimes', 'required', 'string', 'in:SAMSUNG,SHELLY,SONY,PLAYSTATION'],
            'model' => ['nullable', 'string', 'max:50'],
            'mac_address' => ['nullable', 'string', 'max:20', 'regex:/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/i'],
            'ip_address' => ['sometimes', 'required', 'string', 'max:45'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'homebrew_type' => ['nullable', 'string', 'max:50'],
            'homebrew_version' => ['nullable', 'string', 'max:50'],
            'driver_type' => ['nullable', 'string', 'max:50'],
            'transport_config' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
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
            'brand.in' => 'Merek yang didukung: SAMSUNG, SHELLY, SONY, atau PLAYSTATION.',
            'category.in' => 'Kategori harus SMART_TV, SMART_RELAY, atau GAME_CONSOLE.',
        ];
    }
}
