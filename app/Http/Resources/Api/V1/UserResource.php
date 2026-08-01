<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Auth\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar_url' => $this->urlFor($this->avatar_path),
            'avatar_thumbnail_url' => $this->urlFor($this->avatar_thumbnail_path),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a stored avatar path into a publicly reachable URL.
     */
    private function urlFor(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(ProfileService::AVATAR_DISK)->url($path);
    }
}
