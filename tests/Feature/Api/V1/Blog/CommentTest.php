<?php

use App\Jobs\Blog\NotifyAuthorOfComment;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\Blog\PostCommentedOn;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();

    $this->postAuthor = User::factory()->create(['name' => 'Post Author']);
    $this->commenter = User::factory()->create(['name' => 'Commenter']);
    $this->post = Post::factory()->create(['user_id' => $this->postAuthor->id, 'slug' => 'a-post']);
});

/* -------------------------------------------------------------- reading -- */

it('lists a post\'s comments newest first, without a token', function (): void {
    $older = Comment::factory()->create([
        'post_id' => $this->post->id,
        'body' => 'Written earlier',
        'created_at' => now()->subHour(),
    ]);
    $newer = Comment::factory()->create([
        'post_id' => $this->post->id,
        'body' => 'Written later',
        'created_at' => now(),
    ]);

    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonStructure(['data' => [['id', 'body', 'author', 'edited', 'created_at', 'can']], 'meta']);
});

it('only lists comments belonging to the requested post', function (): void {
    Comment::factory()->create(['post_id' => $this->post->id, 'body' => 'Mine']);
    Comment::factory()->create(['body' => 'Someone else\'s post']);

    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Mine');
});

it('paginates comments', function (): void {
    Comment::factory()->count(25)->create(['post_id' => $this->post->id]);

    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);
});

it('exposes the commenter name but never their email', function (): void {
    $commenter = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $commenter->id]);

    $response = $this->getJson(route('api.v1.posts.comments.index', 'a-post'))->assertOk();

    $response->assertJsonPath('data.0.author.name', 'Ada');
    expect(json_encode($response->json()))->not->toContain('ada@example.com');
});

it('returns not found for an unknown post', function (): void {
    $this->getJson(route('api.v1.posts.comments.index', 'no-such-post'))->assertNotFound();
});

/* -------------------------------------------------------------- creating -- */

it('lets an authenticated user comment', function (): void {
    Sanctum::actingAs($this->commenter);

    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), ['body' => 'Great write-up, thanks.'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Great write-up, thanks.')
        ->assertJsonPath('data.author.name', 'Commenter')
        ->assertJsonPath('data.edited', false);

    $this->assertDatabaseHas('comments', [
        'post_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'Great write-up, thanks.',
    ]);
});

it('rejects an unauthenticated comment', function (): void {
    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), ['body' => 'Anonymous'])
        ->assertUnauthorized();

    expect(Comment::query()->count())->toBe(0);
});

it('queues a notification to the post author', function (): void {
    Sanctum::actingAs($this->commenter);

    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), ['body' => 'Nice one.'])->assertCreated();

    Queue::assertPushed(
        NotifyAuthorOfComment::class,
        fn (NotifyAuthorOfComment $job): bool => $job->comment->body === 'Nice one.' && $job->queue === 'mail',
    );
});

it('emails the post author when the job runs', function (): void {
    Notification::fake();

    $comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->commenter->id,
    ]);

    (new NotifyAuthorOfComment($comment))->handle();

    Notification::assertSentTo($this->postAuthor, PostCommentedOn::class);
});

it('does not email an author who commented on their own post', function (): void {
    Notification::fake();

    $comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->postAuthor->id,
    ]);

    (new NotifyAuthorOfComment($comment))->handle();

    Notification::assertNothingSent();
});

it('validates the comment body', function (array $payload, array $errors): void {
    Sanctum::actingAs($this->commenter);

    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'nothing supplied' => [[], ['body']],
    'blank' => [['body' => ''], ['body']],
    'whitespace only' => [['body' => '     '], ['body']],
    'too short' => [['body' => 'a'], ['body']],
    'too long' => [['body' => str_repeat('a', 2001)], ['body']],
]);

it('trims surrounding whitespace from the body', function (): void {
    Sanctum::actingAs($this->commenter);

    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), ['body' => "  spaced out  \n"])
        ->assertCreated()
        ->assertJsonPath('data.body', 'spaced out');
});

/* -------------------------------------------------------------- updating -- */

it('lets the author edit their own comment', function (): void {
    $comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'First draft',
    ]);

    Sanctum::actingAs($this->commenter);

    $this->putJson(route('api.v1.comments.update', $comment), ['body' => 'Second draft'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Second draft')
        ->assertJsonPath('data.edited', true);

    expect($comment->refresh()->body)->toBe('Second draft');
});

it('refuses to let someone edit a comment they did not write', function (): void {
    $comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'Mine',
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.comments.update', $comment), ['body' => 'Hijacked'])->assertForbidden();

    expect($comment->refresh()->body)->toBe('Mine');
});

it('does not let the post author edit a reader\'s comment', function (): void {
    $comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->commenter->id,
        'body' => 'Reader words',
    ]);

    Sanctum::actingAs($this->postAuthor);

    $this->putJson(route('api.v1.comments.update', $comment), ['body' => 'Rewritten'])->assertForbidden();

    expect($comment->refresh()->body)->toBe('Reader words');
});

it('validates the body on update', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    Sanctum::actingAs($this->commenter);

    $this->putJson(route('api.v1.comments.update', $comment), ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('rejects an unauthenticated edit', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    $this->putJson(route('api.v1.comments.update', $comment), ['body' => 'Anonymous edit'])
        ->assertUnauthorized();
});

/* -------------------------------------------------------------- deleting -- */

it('lets the author delete their own comment', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    Sanctum::actingAs($this->commenter);

    $this->deleteJson(route('api.v1.comments.destroy', $comment))
        ->assertOk()
        ->assertJsonStructure(['message']);

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('lets the post author moderate a comment on their post', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    Sanctum::actingAs($this->postAuthor);

    $this->deleteJson(route('api.v1.comments.destroy', $comment))->assertOk();

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('refuses to let an unrelated user delete a comment', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson(route('api.v1.comments.destroy', $comment))->assertForbidden();

    $this->assertDatabaseHas('comments', ['id' => $comment->id]);
});

it('rejects an unauthenticated delete', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    $this->deleteJson(route('api.v1.comments.destroy', $comment))->assertUnauthorized();

    $this->assertDatabaseHas('comments', ['id' => $comment->id]);
});

it('removes a post\'s comments when the post goes away', function (): void {
    Comment::factory()->count(3)->create(['post_id' => $this->post->id]);

    $this->post->delete();

    expect(Comment::query()->count())->toBe(0);
});

/* ------------------------------------------------------ permission flags -- */

/**
 * Read the listing exactly as a browser would: a real bearer token on a route
 * that has no `auth:sanctum` middleware.
 *
 * `Sanctum::actingAs()` is deliberately avoided here — it makes sanctum the
 * default guard, which is precisely the condition this public route does not
 * meet, and would hide a resource that resolves the wrong guard.
 */
function listCommentsAs(?User $viewer)
{
    $headers = $viewer === null
        ? []
        : ['Authorization' => 'Bearer '.$viewer->createToken('test')->plainTextToken];

    return test()->getJson(route('api.v1.posts.comments.index', 'a-post'), $headers);
}

it('tells the comment author they may edit and delete', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    listCommentsAs($this->commenter)
        ->assertOk()
        ->assertJsonPath('data.0.can.update', true)
        ->assertJsonPath('data.0.can.delete', true);

    expect(Comment::query()->find($comment->id))->not->toBeNull();
});

it('tells the post author they may delete but not edit', function (): void {
    Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    listCommentsAs($this->postAuthor)
        ->assertOk()
        ->assertJsonPath('data.0.can.update', false)
        ->assertJsonPath('data.0.can.delete', true);
});

it('tells an unrelated reader they may do neither', function (): void {
    Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    listCommentsAs(User::factory()->create())
        ->assertOk()
        ->assertJsonPath('data.0.can.update', false)
        ->assertJsonPath('data.0.can.delete', false);
});

it('tells a guest they may do neither', function (): void {
    Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    listCommentsAs(null)
        ->assertOk()
        ->assertJsonPath('data.0.can.update', false)
        ->assertJsonPath('data.0.can.delete', false);
});

/* --------------------------------------------------------- comment count -- */

it('reports the comment count on a post', function (): void {
    Comment::factory()->count(3)->create(['post_id' => $this->post->id]);

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.comments_count', 3);
});

it('refreshes the cached count when a comment is added', function (): void {
    $this->getJson(route('api.v1.posts.show', 'a-post'))->assertOk()->assertJsonPath('data.comments_count', 0);

    Sanctum::actingAs($this->commenter);
    $this->postJson(route('api.v1.posts.comments.store', 'a-post'), ['body' => 'Bumping the count.'])->assertCreated();

    startNewRequestLifecycle();

    $this->getJson(route('api.v1.posts.show', 'a-post'))->assertOk()->assertJsonPath('data.comments_count', 1);
    $this->getJson(route('api.v1.posts.index'))->assertOk()->assertJsonPath('data.0.comments_count', 1);
});

it('refreshes the cached count when a comment is deleted', function (): void {
    $comment = Comment::factory()->create(['post_id' => $this->post->id, 'user_id' => $this->commenter->id]);

    $this->getJson(route('api.v1.posts.show', 'a-post'))->assertOk()->assertJsonPath('data.comments_count', 1);

    Sanctum::actingAs($this->commenter);
    $this->deleteJson(route('api.v1.comments.destroy', $comment))->assertOk();

    startNewRequestLifecycle();

    $this->getJson(route('api.v1.posts.show', 'a-post'))->assertOk()->assertJsonPath('data.comments_count', 0);
});
