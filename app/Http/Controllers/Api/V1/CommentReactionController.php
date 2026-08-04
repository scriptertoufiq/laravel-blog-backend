<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReactionRequest;
use App\Models\Comment;
use App\Models\User;
use App\Services\Blog\ReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentReactionController extends Controller
{
    public function __construct(private readonly ReactionService $reactions) {}

    /**
     * Set the caller's reaction on a comment, replacing any earlier one.
     */
    public function update(StoreReactionRequest $request, Comment $comment): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'message' => __('Reaction saved.'),
            'data' => $this->reactions->react($comment, $reader, $request->reactionType()),
        ]);
    }

    /**
     * Take back the caller's reaction on a comment.
     */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json([
            'message' => __('Reaction removed.'),
            'data' => $this->reactions->unreact($comment, $reader),
        ]);
    }
}
