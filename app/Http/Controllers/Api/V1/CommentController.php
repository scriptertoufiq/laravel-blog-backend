<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexCommentRequest;
use App\Http\Requests\Api\V1\StoreCommentRequest;
use App\Http\Requests\Api\V1\UpdateCommentRequest;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\Blog\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    /**
     * List a post's comments, newest first.
     */
    public function index(IndexCommentRequest $request, Post $post): ResourceCollection
    {
        return CommentResource::collection(
            $this->comments->paginate($post, $request->page(), $request->perPage()),
        );
    }

    /**
     * Leave a comment on a post.
     */
    public function store(StoreCommentRequest $request, Post $post): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        $comment = $this->comments->create($post, $author, $request->body());

        return CommentResource::make($comment)
            ->additional(['message' => __('Comment posted.')])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Edit a comment the caller wrote.
     */
    public function update(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        Gate::authorize('update', $comment);

        $comment = $this->comments->update($comment, $request->body());

        return CommentResource::make($comment)
            ->additional(['message' => __('Comment updated.')])
            ->response();
    }

    /**
     * Delete a comment the caller wrote, or one on their own post.
     */
    public function destroy(Comment $comment): JsonResponse
    {
        Gate::authorize('delete', $comment);

        $this->comments->delete($comment);

        return response()->json(['message' => __('Comment deleted.')]);
    }
}
