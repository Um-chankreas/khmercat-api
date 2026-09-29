<?php

namespace App\Http\Requests;

use App\Helpers\ApiResponse;
use App\Models\Restaurant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        return [
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:restaurant_categories,id',
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240', // 2MB Max
            'description' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            // Optional details shown on search result cards.
            'price_level' => 'nullable|integer|between:1,4',
            'service_type' => 'nullable|in:'.implode(',', Restaurant::SERVICE_TYPES),
            'delivery_time_min' => 'nullable|integer|min:1|max:600',
            'delivery_time_max' => 'nullable|integer|min:1|max:600|gte:delivery_time_min',
            'opening_time' => 'nullable|required_with:closing_time|date_format:H:i',
            'closing_time' => 'nullable|required_with:opening_time|date_format:H:i',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ApiResponse::validationError($validator->errors())
        );
    }
}
