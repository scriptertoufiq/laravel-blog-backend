<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexPostRequest;
use App\Http\Requests\Api\V1\StorePostRequest;
use App\Http\Requests\Api\V1\UpdatePostRequest;
use App\Http\Resources\Api\V1\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    /**
     * List posts, newest first, served from cache when one is warm.
     */
    public function index(IndexPostRequest $request): JsonResponse
    {
        return response()->json(
            // The guard is named because this route is public: without it a
            // reader holding a token would be read as a guest and shown none
            // of their own reactions.
            $this->posts->paginate($request->page(), $request->perPage(), $request->user('sanctum')),
        );
    }

    /**
     * Show a single post, served from cache when one is warm.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        return response()->json($this->posts->findBySlug($slug, $request->user('sanctum')));
    }

    /**
     * Create a post for the authenticated author.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        $post = $this->posts->create(
            $author,
            $request->postData(),
            $request->coverImage(),
            $request->tags(),
        );

        return PostResource::make($post)
            ->additional(['message' => __('Post created successfully.')])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update a post the caller wrote, refreshing its cached copy.
     */
    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $post = $this->posts->update(
            $post,
            $request->postData(),
            $request->coverImage(),
            $request->tags(),
        );

        return PostResource::make($post)
            ->additional(['message' => __('Post updated successfully.')])
            ->response();
    }
}
