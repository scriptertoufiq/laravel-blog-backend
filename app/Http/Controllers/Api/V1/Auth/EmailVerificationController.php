<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SendVerificationEmailRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    /**
     * Verify an email address from a signed link.
     */
    public function verify(int $id, string $hash): JsonResponse
    {
        ['user' => $user, 'newly_verified' => $newlyVerified] = $this->verification->verify($id, $hash);

        return UserResource::make($user)
            ->additional([
                'message' => $newlyVerified
                    ? __('Your email address has been verified.')
                    : __('Your email address was already verified.'),
            ])
            ->response();
    }

    /**
     * Queue another verification link for the given email address.
     */
    public function resend(SendVerificationEmailRequest $request): JsonResponse
    {
        $this->verification->resendVerificationLink($request->string('email')->value());

        return response()->json([
            'message' => __('If the address belongs to an unverified account, a new verification link has been sent.'),
        ], Response::HTTP_ACCEPTED);
    }
}
