<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    /**
     * Exchange credentials for a personal access token.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->authentication->login(
            $request->credentials(),
            $request->deviceName(),
            (string) $request->ip(),
        );

        return UserResource::make($user)
            ->additional([
                'message' => __('Logged in successfully.'),
                'token' => $token,
                'token_type' => 'Bearer',
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
