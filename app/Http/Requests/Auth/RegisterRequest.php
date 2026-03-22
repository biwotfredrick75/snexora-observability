<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register Request
 * 
 * Validates user registration data.
 */
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
     */
    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:100',
            'user_id'  => 'nullable|string|max:60|unique:users,user_id',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone'    => 'nullable|string|max:30',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required'     => 'Name is required',
            'user_id.unique'    => 'User ID already exists',
            'email.required'    => 'Email is required',
            'email.unique'      => 'Email already exists',
            'password.required' => 'Password is required',
            'password.min'      => 'Password must be at least 8 characters',
            'password.confirmed'=> 'Passwords do not match',
        ];
    }
}
