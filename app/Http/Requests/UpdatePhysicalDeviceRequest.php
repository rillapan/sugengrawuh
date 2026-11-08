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
            'category' => ['sometimes', 'required', 'string', 'in:SMART_TV,SMART_RELAY'],
            'brand' => ['sometimes', 'required', 'string', 'in:SAMSUNG,SHELLY'],
            'model' => ['nullable', 'string', 'max:50'],
            'mac_address' => ['sometimes', 'required', 'string', 'max:20'],
            'ip_address' => ['sometimes', 'required', 'string', 'max:45'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
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
            'brand.in' => 'Merek yang didukung hanya SAMSUNG atau SHELLY.',
            'category.in' => 'Kategori harus SMART_TV atau SMART_RELAY.',
        ];
    }
}
