<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config([
        'services.anthropic.key' => 'test-key',
        'services.anthropic.model' => 'claude-opus-5',
        'services.anthropic.base_url' => 'https://api.anthropic.com',
        'services.anthropic.effort' => 'low',
        'services.anthropic.timeout' => 60,
    ]);

    $this->author = User::factory()->create();
    Sanctum::actingAs($this->author);
});

/**
 * Build a Messages API response carrying a drafted post.
 *
 * @param  array<int, string>  $tags
 * @return array<string, mixed>
 */
function draftResponse(string $description, array $tags = [], string $stopReason = 'end_turn'): array
{
    return [
        'id' => 'msg_01',
        'model' => 'claude-opus-5',
        'stop_reason' => $stopReason,
        'content' => [
            ['type' => 'text', 'text' => json_encode(['description' => $description, 'tags' => $tags])],
        ],
    ];
}

it('rejects an unauthenticated request', function (): void {
    startNewRequestLifecycle();
    Http::fake();

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Anonymous Draft'])
        ->assertUnauthorized();

    Http::assertNothingSent();
});

it('returns a drafted body and tags for a title', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            draftResponse('## Why queues matter'."\n\n".'A body long enough to pass the floor.', ['laravel', 'queues']),
        ),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Laravel Queues In Practice'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Laravel Queues In Practice')
        ->assertJsonPath('data.tags', ['laravel', 'queues'])
        ->assertJsonPath('message', 'Draft generated successfully.')
        ->assertJsonStructure(['message', 'data' => ['title', 'description', 'tags']]);
});

it('never persists the draft it hands back', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('A body long enough to pass the floor.')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Not A Real Post'])->assertOk();

    expect(Post::query()->count())->toBe(0);
});

it('sends the configured model, title and credentials to the provider', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('A body long enough to pass the floor.')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Caching Strategies'])->assertOk();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-opus-5'
            && $request['output_config']['effort'] === 'low'
            && $request['output_config']['format']['type'] === 'json_schema'
            && str_contains($request['messages'][0]['content'], 'Caching Strategies');
    });
});

it('trims the title before drafting and echoes the trimmed version back', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('A body long enough to pass the floor.')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => "  Spaced   Out   Title \n"])
        ->assertOk()
        ->assertJsonPath('data.title', 'Spaced Out Title');

    Http::assertSent(fn (Request $request): bool => $request['messages'][0]['content'] === 'Title: Spaced Out Title');
});

it('drops suggested tags the post endpoints would reject', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse(
            'A body long enough to pass the floor.',
            ['laravel', 'a', str_repeat('x', 51), '  Laravel  ', 'queues', 'caching', 'testing', 'php'],
        )),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Messy Tags'])
        ->assertOk()
        ->assertJsonPath('data.tags', ['laravel', 'queues', 'caching', 'testing', 'php']);
});

it('validates the title', function (array $payload, array $errors): void {
    Http::fake();

    $this->postJson(route('api.v1.posts.generate'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    Http::assertNothingSent();
})->with([
    'nothing supplied' => [[], ['title']],
    'blank title' => [['title' => ''], ['title']],
    'title too short' => [['title' => 'ab'], ['title']],
    'title too long' => [['title' => str_repeat('a', 256)], ['title']],
    'title not a string' => [['title' => ['nope']], ['title']],
]);

it('reports that drafting is unavailable when no api key is configured', function (): void {
    config(['services.anthropic.key' => '']);
    Http::fake();

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'No Key Here'])
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'ai_not_configured');

    Http::assertNothingSent();
});

it('reports a bad gateway when the provider errors', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Provider Is Down'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_request_failed');
});

it('retries a transient provider failure before giving up', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 529)
            ->push(draftResponse('A body long enough to pass the floor.')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Second Time Lucky'])->assertOk();

    Http::assertSentCount(2);
});

it('does not retry a request the provider refuses to accept', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad request']], 400),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Malformed Request'])->assertStatus(502);

    Http::assertSentCount(1);
});

it('surfaces a provider refusal as a bad request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
            'content' => [],
        ]),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Something Disallowed'])
        ->assertBadRequest()
        ->assertJsonPath('code', 'ai_refused');
});

it('rejects a draft that was cut short by the token ceiling', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('A truncated body.', [], 'max_tokens')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Truncated Draft'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_bad_response');
});

it('rejects a response that is not the json the schema asked for', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Sure! Here is your post.']],
        ]),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Unparseable Draft'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_bad_response');
});

it('rejects a draft body too short for the post endpoints to accept', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('Too short')),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Thin Draft'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_bad_response');
});

it('reads the draft past a leading non-text block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5'], 'to' => ['model' => 'claude-opus-4-8']],
                ['type' => 'text', 'text' => json_encode(['description' => 'A body long enough to pass the floor.', 'tags' => ['php']])],
            ],
        ]),
    ]);

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'Rescued By A Fallback'])
        ->assertOk()
        ->assertJsonPath('data.tags', ['php']);
});

it('throttles an author who drafts too often', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(draftResponse('A body long enough to pass the floor.')),
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.posts.generate'), ['title' => 'Draft Number '.$attempt])->assertOk();
    }

    $this->postJson(route('api.v1.posts.generate'), ['title' => 'One Too Many'])
        ->assertTooManyRequests();

    Http::assertSentCount(5);
});
