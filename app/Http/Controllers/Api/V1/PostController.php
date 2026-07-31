<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePostRequest;
use App\Http\Resources\Api\V1\PostResource;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

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
}
