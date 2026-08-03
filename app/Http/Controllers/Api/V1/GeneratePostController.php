<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GeneratePostRequest;
use App\Services\Ai\PostGenerator;
use Illuminate\Http\JsonResponse;

class GeneratePostController extends Controller
{
    public function __construct(private readonly PostGenerator $generator) {}

    /**
     * Draft a post body and tag list from a title.
     *
     * Nothing is persisted. The draft goes back to the editor for the author to
     * accept, rewrite, or throw away before any post exists.
     */
    public function __invoke(GeneratePostRequest $request): JsonResponse
    {
        $title = $request->title();

        return response()->json([
            'message' => __('Draft generated successfully.'),
            'data' => [
                'title' => $title,
                ...$this->generator->generate($title),
            ],
        ]);
    }
}
