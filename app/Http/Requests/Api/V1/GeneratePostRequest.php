<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class GeneratePostRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The title is held to the same bounds as `StorePostRequest`, so a draft
     * can never be written for a title the author would then be unable to save.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * Guarded on the input actually being a string: a client that sends an
     * array would otherwise blow up in `Str::squish` before the rules — which
     * exist to reject exactly that — ever get a chance to run.
     */
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            $this->merge(['title' => Str::squish($title)]);
        }
    }

    /**
     * Get the title the draft should be written for.
     */
    public function title(): string
    {
        return $this->safe()->string('title')->value();
    }
}
