<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReactionType::class)],
        ];
    }

    /**
     * Get the human-readable messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.enum' => __('Choose one of: :types.', ['types' => implode(', ', ReactionType::values())]),
        ];
    }

    /**
     * Get the reaction the reader picked.
     */
    public function reactionType(): ReactionType
    {
        return ReactionType::from((string) $this->validated('type'));
    }
}
