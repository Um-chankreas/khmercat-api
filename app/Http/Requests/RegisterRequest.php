<?php

namespace App\Http\Requests;

use App\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|string|email|max:100',
            'password' => 'required|string|min:6',
            // The Terms of Service checkbox on the sign-up screen.
            'terms_accepted' => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => 'Please accept the Terms of Service to sign up.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(ApiResponse::validationError($validator->errors()));
    }
}
