<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => $this->string('name')->squish()->value()]);
        }

        if ($this->has('email')) {
            $this->merge(['email' => $this->string('email')->trim()->lower()->value()]);
        }
    }

    /**
     * Get the attributes that should be persisted for the new user.
     *
     * @return array{name: string, email: string, password: string}
     */
    public function registrationData(): array
    {
        /** @var array{name: string, email: string, password: string} */
        return $this->safe()->only(['name', 'email', 'password']);
    }
}
