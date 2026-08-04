<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Comment;
use App\Services\Blog\ReactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The guard is named explicitly because this resource is also served
        // from the public comment listing, where no `auth:sanctum` middleware
        // has run to make sanctum the default. Without it every viewer would
        // be read as a guest and told they can do nothing.
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => AuthorResource::make($this->whenLoaded('author')),
            'edited' => $this->wasEdited(),
            // Counts and the viewer's own pick are eager loaded by the comment
            // service, so this costs no query however long the page is.
            'reactions' => app(ReactionService::class)
                ->summarizeLoaded($this->resource, $this->viewerReactionType()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Told to the client so it can show the right controls rather than
            // guessing at the policy and getting a surprise 403.
            'can' => [
                'update' => $viewer?->can('update', $this->resource) ?? false,
                'delete' => $viewer?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
