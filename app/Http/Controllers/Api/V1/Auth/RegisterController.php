<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\UserRegistrationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function __construct(private readonly UserRegistrationService $registration) {}

    /**
     * Register a new user and queue their email verification link.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->registration->register($request->registrationData());

        return UserResource::make($user)
            ->additional([
                'message' => __('Registration successful. Please check your email to verify your address.'),
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
