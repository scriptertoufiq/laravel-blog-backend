<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Media\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UpdatePostRequest extends FormRequest
{
    /**
     * The largest image accepted, in kilobytes.
     */
    private const MAX_IMAGE_KILOBYTES = 5120;

    /**
     * Get the validation rules that apply to the request.
     *
     * Every field is optional so a client can send just the part it changed,
     * but anything that is sent must still be valid.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10', 'max:65535'],
            'image' => [
                'sometimes',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.$this->maxUploadKilobytes(),
                'dimensions:min_width=200,min_height=200',
            ],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'min:2', 'max:50', 'distinct:ignore_case'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => $this->string('title')->squish()->value()]);
        }

        if (is_array($this->input('tags'))) {
            $this->merge(['tags' => $this->normalisedTags()]);
        }
    }

    /**
     * Get only the post attributes the client actually sent.
     *
     * @return array<string, string>
     */
    public function postData(): array
    {
        /** @var array<string, string> */
        return $this->safe()->only(['title', 'description']);
    }

    /**
     * Get the replacement cover image, when one was supplied.
     *
     * Named `coverImage` rather than `image` because `Illuminate\Http\Request`
     * already declares an incompatible `image()` method.
     */
    public function coverImage(): ?UploadedFile
    {
        $image = $this->file('image');

        return $image instanceof UploadedFile ? $image : null;
    }

    /**
     * Get the replacement tag list, or null when tags were not part of the request.
     *
     * The distinction matters: an omitted `tags` key leaves the existing tags
     * alone, while an empty array clears them.
     *
     * @return array<int, string>|null
     */
    public function tags(): ?array
    {
        if (! $this->has('tags')) {
            return null;
        }

        /** @var array<int, string> */
        return $this->safe()->array('tags');
    }

    /**
     * Collapse whitespace inside tag entries without dropping any.
     *
     * @return array<int, mixed>
     */
    private function normalisedTags(): array
    {
        /** @var array<int, mixed> $tags */
        $tags = $this->input('tags', []);

        return collect($tags)
            ->map(fn (mixed $tag): mixed => is_string($tag) ? Str::squish($tag) : $tag)
            ->all();
    }

    /**
     * The effective size cap, clamped to what PHP will actually accept.
     *
     * Without the clamp an oversized upload is killed by PHP with a bare 413
     * instead of reaching this rule and producing a readable message.
     */
    private function maxUploadKilobytes(): int
    {
        return app(UploadLimits::class)->maxKilobytes(self::MAX_IMAGE_KILOBYTES);
    }
}
