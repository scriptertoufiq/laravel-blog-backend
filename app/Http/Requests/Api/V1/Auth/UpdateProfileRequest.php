<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Both fields are optional so a client can send only what changed, but
     * anything sent must be valid.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                // The caller's own address must not collide with itself.
                Rule::unique(User::class, 'email')->ignore($this->user()?->getKey()),
            ],
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
     * Get only the profile attributes the client actually sent.
     *
     * @return array<string, string>
     */
    public function profileData(): array
    {
        /** @var array<string, string> */
        return $this->safe()->only(['name', 'email']);
    }
}
