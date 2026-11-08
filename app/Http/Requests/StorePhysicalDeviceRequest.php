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
            'category' => ['required', 'string', 'in:SMART_TV,SMART_RELAY'],
            'brand' => ['required', 'string', 'in:SAMSUNG,SHELLY'],
            'model' => ['nullable', 'string', 'max:50'],
            'mac_address' => ['required', 'string', 'max:20'],
            'ip_address' => ['required', 'string', 'max:45'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
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
            'brand.in' => 'Merek yang didukung hanya SAMSUNG (Smart TV) atau SHELLY (Smart Relay). Merek COOCAA atau lainnya tidak diizinkan.',
            'category.in' => 'Kategori harus SMART_TV atau SMART_RELAY.',
        ];
    }
}
