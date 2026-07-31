<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Post;
use App\Services\Blog\PostService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->urlFor($this->image_path),
            'image_thumbnail_url' => $this->urlFor($this->image_thumbnail_path),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'author' => AuthorResource::make($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a stored image path into a publicly reachable URL.
     */
    private function urlFor(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(PostService::IMAGE_DISK)->url($path);
    }
}
