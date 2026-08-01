<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Models\User;
use App\Services\Media\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * The largest avatar accepted, in kilobytes.
     */
    private const MAX_AVATAR_KILOBYTES = 2048;

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
            'avatar' => [
                'sometimes',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$this->maxUploadKilobytes(),
                'dimensions:min_width=100,min_height=100',
            ],
            // Sent instead of a file to clear the current picture.
            'remove_avatar' => ['sometimes', 'boolean', 'prohibits:avatar'],
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

    /**
     * Get the uploaded avatar, when one was supplied.
     *
     * Named `avatarFile` rather than `avatar` to stay clear of any accessor
     * `Illuminate\Http\Request` may define, the way `image()` already collides.
     */
    public function avatarFile(): ?UploadedFile
    {
        $avatar = $this->file('avatar');

        return $avatar instanceof UploadedFile ? $avatar : null;
    }

    /**
     * Whether the client asked to clear the current avatar.
     */
    public function wantsAvatarRemoved(): bool
    {
        return $this->boolean('remove_avatar');
    }

    /**
     * The effective size cap, clamped to what PHP will actually accept.
     *
     * Without the clamp an oversized upload is killed by PHP with a bare 413
     * instead of reaching this rule and producing a readable message.
     */
    private function maxUploadKilobytes(): int
    {
        return app(UploadLimits::class)->maxKilobytes(self::MAX_AVATAR_KILOBYTES);
    }
}
