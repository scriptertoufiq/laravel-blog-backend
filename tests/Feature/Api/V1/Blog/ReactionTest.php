<?php

use App\Enums\ReactionType;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();

    $this->author = User::factory()->create(['name' => 'Post Author']);
    $this->reader = User::factory()->create(['name' => 'Reader']);
    $this->post = Post::factory()->create(['user_id' => $this->author->id, 'slug' => 'a-post']);
    $this->comment = Comment::factory()->create([
        'post_id' => $this->post->id,
        'user_id' => $this->author->id,
    ]);
});

/* -------------------------------------------------------------- writing -- */

it('records a reaction on a post', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'love'])
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.counts.love', 1)
        ->assertJsonPath('data.counts.like', 0)
        ->assertJsonPath('data.viewer_reaction', 'love');

    $this->assertDatabaseHas('reactions', [
        'reactable_type' => Post::class,
        'reactable_id' => $this->post->id,
        'user_id' => $this->reader->id,
        'type' => 'love',
    ]);
});

it('reports every reaction type, including the ones nobody picked', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])
        ->assertOk()
        ->assertJsonPath('data.counts', [
            'like' => 1,
            'love' => 0,
            'laugh' => 0,
            'insightful' => 0,
            'sad' => 0,
        ]);
});

it('replaces an earlier reaction rather than stacking a second one', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])->assertOk();

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'laugh'])
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.counts.like', 0)
        ->assertJsonPath('data.counts.laugh', 1)
        ->assertJsonPath('data.viewer_reaction', 'laugh');

    expect(Reaction::query()->count())->toBe(1);
});

it('leaves the same reaction sent twice unchanged', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'sad'])->assertOk();

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'sad'])
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    expect(Reaction::query()->count())->toBe(1);
});

it('counts one reaction per reader', function (): void {
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Like)->create();
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Like)->create();

    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'love'])
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.counts.like', 2)
        ->assertJsonPath('data.counts.love', 1);
});

it('takes a reaction back', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])->assertOk();

    $this->deleteJson(route('api.v1.posts.reactions.destroy', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.viewer_reaction', null);

    $this->assertDatabaseCount('reactions', 0);
});

it('treats removing a reaction that was never left as a success', function (): void {
    Sanctum::actingAs($this->reader);

    $this->deleteJson(route('api.v1.posts.reactions.destroy', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('only removes the caller\'s own reaction', function (): void {
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Like)->create();

    Sanctum::actingAs($this->reader);
    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'love'])->assertOk();

    $this->deleteJson(route('api.v1.posts.reactions.destroy', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.counts.like', 1)
        ->assertJsonPath('data.viewer_reaction', null);
});

it('records a reaction on a comment', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.comments.reactions.update', $this->comment), ['type' => 'insightful'])
        ->assertOk()
        ->assertJsonPath('data.counts.insightful', 1)
        ->assertJsonPath('data.viewer_reaction', 'insightful');

    $this->assertDatabaseHas('reactions', [
        'reactable_type' => Comment::class,
        'reactable_id' => $this->comment->id,
        'type' => 'insightful',
    ]);
});

it('keeps a post\'s reactions apart from its comment\'s', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])->assertOk();

    $this->putJson(route('api.v1.comments.reactions.update', $this->comment), ['type' => 'love'])
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.counts.like', 0)
        ->assertJsonPath('data.counts.love', 1);
});

it('lets an author react to their own post', function (): void {
    Sanctum::actingAs($this->author);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])
        ->assertOk()
        ->assertJsonPath('data.viewer_reaction', 'like');
});

/* ------------------------------------------------------------ rejection -- */

it('rejects a reaction type it does not recognise', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'shrug'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');

    $this->assertDatabaseCount('reactions', 0);
});

it('requires a reaction type', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('turns a guest away', function (): void {
    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])
        ->assertUnauthorized();

    $this->deleteJson(route('api.v1.posts.reactions.destroy', 'a-post'))
        ->assertUnauthorized();

    $this->putJson(route('api.v1.comments.reactions.update', $this->comment), ['type' => 'like'])
        ->assertUnauthorized();
});

it('404s on a post that does not exist', function (): void {
    Sanctum::actingAs($this->reader);

    $this->putJson(route('api.v1.posts.reactions.update', 'no-such-post'), ['type' => 'like'])
        ->assertNotFound();
});

/* -------------------------------------------------------------- reading -- */

it('reports a post\'s reactions on the listing and the single post', function (): void {
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Like)->create();
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Sad)->create();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.reactions.total', 2)
        ->assertJsonPath('data.0.reactions.counts.like', 1)
        ->assertJsonPath('data.0.reactions.viewer_reaction', null);

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.reactions.total', 2)
        ->assertJsonPath('data.reactions.counts.sad', 1);
});

it('tells a signed-in reader which reaction is theirs', function (): void {
    Reaction::factory()->forReactable($this->post)->ofType(ReactionType::Like)->create();

    Sanctum::actingAs($this->reader);
    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'love'])->assertOk();

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.reactions.total', 2)
        ->assertJsonPath('data.reactions.viewer_reaction', 'love');
});

it('never serves one reader\'s reaction to another out of the post cache', function (): void {
    Sanctum::actingAs($this->reader);
    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'love'])->assertOk();

    // Warms the cached payload while the first reader is the one asking.
    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertJsonPath('data.reactions.viewer_reaction', 'love');

    startNewRequestLifecycle();
    Sanctum::actingAs($this->author);

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.reactions.total', 1)
        ->assertJsonPath('data.reactions.viewer_reaction', null);

    startNewRequestLifecycle();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.reactions.viewer_reaction', null);
});

it('keeps a fresh reaction visible even though the post itself is cached', function (): void {
    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertJsonPath('data.reactions.total', 0);

    Sanctum::actingAs($this->reader);
    $this->putJson(route('api.v1.posts.reactions.update', 'a-post'), ['type' => 'like'])->assertOk();

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.reactions.total', 1);
});

it('reports a comment\'s reactions to a guest and to its owner alike', function (): void {
    Reaction::factory()->forReactable($this->comment)->ofType(ReactionType::Laugh)->create();

    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.0.reactions.total', 1)
        ->assertJsonPath('data.0.reactions.counts.laugh', 1)
        ->assertJsonPath('data.0.reactions.viewer_reaction', null);

    Sanctum::actingAs($this->reader);
    $this->putJson(route('api.v1.comments.reactions.update', $this->comment), ['type' => 'love'])->assertOk();

    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.0.reactions.total', 2)
        ->assertJsonPath('data.0.reactions.viewer_reaction', 'love');
});

it('reads a page of comments without spending a query per reaction', function (): void {
    Comment::factory()->count(5)->create(['post_id' => $this->post->id])
        ->each(fn (Comment $comment) => Reaction::factory()->forReactable($comment)->create());

    Sanctum::actingAs($this->reader);

    // One throwaway request first, so the one being measured is not also
    // paying for whatever the framework only ever resolves once.
    $this->getJson(route('api.v1.posts.comments.index', 'a-post'))->assertOk();
    startNewRequestLifecycle();

    $fewComments = queriesDuring(function (): void {
        $this->getJson(route('api.v1.posts.comments.index', 'a-post'))->assertOk();
    });

    startNewRequestLifecycle();

    Comment::factory()->count(5)->create(['post_id' => $this->post->id])
        ->each(fn (Comment $comment) => Reaction::factory()->forReactable($comment)->create());

    $moreComments = queriesDuring(function (): void {
        $this->getJson(route('api.v1.posts.comments.index', 'a-post'))->assertOk();
    });

    expect($moreComments)->toBe($fewComments);
});

/* ------------------------------------------------------------- clean up -- */

it('clears a comment\'s reactions when the comment is deleted', function (): void {
    Reaction::factory()->forReactable($this->comment)->create();

    Sanctum::actingAs($this->author);

    $this->deleteJson(route('api.v1.comments.destroy', $this->comment))->assertOk();

    $this->assertDatabaseCount('reactions', 0);
});

it('clears reactions on a post and on its comments when the post is deleted', function (): void {
    Reaction::factory()->forReactable($this->post)->create();
    Reaction::factory()->forReactable($this->comment)->create();

    $this->post->delete();

    $this->assertDatabaseCount('reactions', 0);
});

it('clears a reader\'s reactions when their account is deleted', function (): void {
    $reaction = Reaction::factory()->forReactable($this->post)->create(['user_id' => $this->reader->id]);

    $this->reader->delete();

    $this->assertDatabaseMissing('reactions', ['id' => $reaction->id]);
});
