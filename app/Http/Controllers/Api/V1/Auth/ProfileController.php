<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\ProfileService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profile) {}

    /**
     * Update the signed-in user's name and email address.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $emailChanged = $request->has('email')
            && $request->validated('email') !== $user->email;

        $user = $this->profile->update($user, $request->profileData());

        return UserResource::make($user)
            ->additional([
                'message' => $emailChanged
                    ? __('Profile updated. Please verify your new email address.')
                    : __('Profile updated.'),
            ])
            ->response();
    }

    /**
     * Replace the signed-in user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->profile->updatePassword(
            $user,
            $request->newPassword(),
            $user->currentAccessToken()?->getKey(),
        );

        return UserResource::make($user)
            ->additional([
                'message' => __('Password updated. Your other devices have been signed out.'),
            ])
            ->response();
    }
}
