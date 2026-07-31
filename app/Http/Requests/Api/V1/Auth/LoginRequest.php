<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => $this->string('email')->trim()->lower()->value()]);
        }
    }

    /**
     * Get the credentials that should be checked against the user record.
     *
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        /** @var array{email: string, password: string} */
        return $this->safe()->only(['email', 'password']);
    }

    /**
     * Get the label to store the issued token under.
     *
     * Falls back to the user agent so tokens remain distinguishable when the
     * client does not name the device itself.
     */
    public function deviceName(): string
    {
        $deviceName = $this->string('device_name')->trim();

        if ($deviceName->isNotEmpty()) {
            return Str::limit($deviceName->value(), 255, '');
        }

        return Str::limit((string) $this->userAgent(), 255, '') ?: 'api';
    }
}
