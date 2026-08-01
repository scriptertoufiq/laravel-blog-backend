<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Auth\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The public face of a post's author.
 *
 * Deliberately narrower than `UserResource`, which carries the email address
 * and verification state — none of which belongs in a public blog payload.
 *
 * @mixin User
 */
class AuthorResource extends JsonResource
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
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl($this->avatar_thumbnail_path ?? $this->avatar_path),
        ];
    }

    /**
     * Resolve a stored avatar path into a publicly reachable URL.
     */
    private function avatarUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(ProfileService::AVATAR_DISK)->url($path);
    }
}
