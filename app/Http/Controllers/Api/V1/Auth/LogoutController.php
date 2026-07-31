<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    /**
     * Revoke the token that authenticated the current request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->authentication->logout($request);

        return response()->json(['message' => __('Logged out successfully.')]);
    }
}
