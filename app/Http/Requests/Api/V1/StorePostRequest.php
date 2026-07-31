<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class StorePostRequest extends FormRequest
{
    /**
     * The largest image accepted, in kilobytes.
     */
    private const MAX_IMAGE_KILOBYTES = 5120;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:65535'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::MAX_IMAGE_KILOBYTES,
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
     * Get the attributes that should be persisted for the new post.
     *
     * @return array{title: string, description: string}
     */
    public function postData(): array
    {
        /** @var array{title: string, description: string} */
        return $this->safe()->only(['title', 'description']);
    }

    /**
     * Get the uploaded cover image, when one was supplied.
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
     * Get the tag names the post should carry.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        /** @var array<int, string> */
        return $this->safe()->array('tags');
    }

    /**
     * Collapse whitespace inside tag entries.
     *
     * Entries are only tidied here, never dropped — deciding what counts as a
     * valid tag is the rule set's job, and silently discarding an entry would
     * hide bad input from the client instead of reporting it.
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
}
