<?php

namespace App\Exceptions\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Raised when a post draft could not be produced.
 *
 * Drafting talks to a third party over the network, so it fails in ways the
 * author can do nothing about. Each failure renders itself as a status the
 * client can act on, carrying a message that is safe to show verbatim — the
 * provider's own error body is logged rather than forwarded, because it may
 * name internal endpoints or quota details that are nobody else's business.
 */
class PostGenerationFailed extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * No API credentials are present, so drafting can never succeed here.
     */
    public static function notConfigured(): self
    {
        return new self(
            __('AI drafting is not available on this server.'),
            'ai_not_configured',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * The provider could not be reached at all.
     */
    public static function unreachable(Throwable $previous): self
    {
        return new self(
            __('The writing assistant could not be reached. Please try again.'),
            'ai_unreachable',
            Response::HTTP_SERVICE_UNAVAILABLE,
            $previous,
        );
    }

    /**
     * The provider answered, but with an error status.
     */
    public static function providerRejected(): self
    {
        return new self(
            __('The writing assistant is unavailable right now. Please try again shortly.'),
            'ai_request_failed',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    /**
     * The provider answered successfully but sent back nothing usable.
     */
    public static function unusableResponse(): self
    {
        return new self(
            __('The writing assistant returned a draft that could not be read. Please try again.'),
            'ai_bad_response',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    /**
     * The provider declined to write about this title.
     *
     * A 400 rather than a 422: the title is what the caller must change, but
     * there is no per-field rule to report, and clients treat 422 as "a rule
     * was broken" and go looking for an `errors` map that will never be there.
     */
    public static function refused(): self
    {
        return new self(
            __('The writing assistant declined to draft this post. Try a different title.'),
            'ai_refused',
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->reason],
            $this->status,
        );
    }
}
