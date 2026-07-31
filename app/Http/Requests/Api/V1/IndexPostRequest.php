<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IndexPostRequest extends FormRequest
{
    /**
     * The number of posts returned when the client does not ask for a size.
     */
    public const DEFAULT_PER_PAGE = 15;

    /**
     * The largest page the listing will serve.
     *
     * Both bounds are deliberately tight: every distinct page and size pair
     * becomes its own cache entry, so leaving them open would let a caller
     * flood the cache with junk keys.
     */
    private const MAX_PAGE = 1000;

    /**
     * The largest page size the listing will serve.
     */
    private const MAX_PER_PAGE = 50;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * Get the page number being requested.
     */
    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    /**
     * Get the number of posts to return per page.
     */
    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }
}
