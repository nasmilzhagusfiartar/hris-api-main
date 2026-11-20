<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $storeId = $this->route('store');

        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('stores', 'code')->ignore($storeId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand' => ['sometimes', 'required', 'string', Rule::in(Store::BRANDS)],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
