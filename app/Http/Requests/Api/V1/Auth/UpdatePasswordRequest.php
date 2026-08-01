<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `current_password` is required even though the caller already holds a
     * valid token: a stolen token should not be enough to lock the real owner
     * out of their own account.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    /**
     * Get the custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => __('The password you entered is incorrect.'),
            'password.different' => __('The new password must be different from your current one.'),
        ];
    }

    /**
     * Get the replacement password.
     */
    public function newPassword(): string
    {
        return (string) $this->validated('password');
    }
}
