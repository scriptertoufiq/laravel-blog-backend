<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when valid credentials belong to an account whose email address has
 * not been confirmed yet. Carries a machine-readable code so clients can offer
 * to request a fresh verification link.
 */
class EmailNotVerifiedException extends Exception implements ShouldntReport
{
    public function __construct(string $message = 'Your email address is not verified.')
    {
        parent::__construct($message);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __($this->getMessage()),
            'code' => 'email_not_verified',
        ], Response::HTTP_FORBIDDEN);
    }
}
