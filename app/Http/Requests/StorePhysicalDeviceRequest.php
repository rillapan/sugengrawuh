<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePhysicalDeviceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'in:SMART_TV,SMART_RELAY,GAME_CONSOLE'],
            'brand' => ['required', 'string', 'in:SAMSUNG,SHELLY,SONY,PLAYSTATION'],
            'model' => ['nullable', 'string', 'max:50'],
            'mac_address' => ['nullable', 'required_unless:category,GAME_CONSOLE', 'string', 'max:20'],
            'ip_address' => ['required', 'string', 'max:45'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'homebrew_type' => ['nullable', 'string', 'max:50'],
            'homebrew_version' => ['nullable', 'string', 'max:50'],
            'driver_type' => ['nullable', 'string', 'max:50'],
            'transport_config' => ['nullable', 'array'],
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
            'brand.in' => 'Merek yang didukung: SAMSUNG (Smart TV), SHELLY (Smart Relay), atau SONY / PLAYSTATION (Game Console).',
            'category.in' => 'Kategori harus SMART_TV, SMART_RELAY, atau GAME_CONSOLE.',
        ];
    }
}
