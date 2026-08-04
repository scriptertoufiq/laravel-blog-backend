<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReactionRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Blog\ReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostReactionController extends Controller
{
    public function __construct(private readonly ReactionService $reactions) {}

    /**
     * Set the caller's reaction on a post, replacing any earlier one.
     *
     * Idempotent by design — sending the same reaction twice leaves the post in
     * the state the caller asked for, which is why this is a `PUT`.
     */
    public function update(StoreReactionRequest $request, Post $post): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'message' => __('Reaction saved.'),
            'data' => $this->reactions->react($post, $reader, $request->reactionType()),
        ]);
    }

    /**
     * Take back the caller's reaction on a post.
     */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'message' => __('Reaction removed.'),
            'data' => $this->reactions->unreact($post, $reader),
        ]);
    }
}
