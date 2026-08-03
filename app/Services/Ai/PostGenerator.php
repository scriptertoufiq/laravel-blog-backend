<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\PostGenerationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drafts the body of a post from nothing but its title.
 *
 * The draft is deliberately handed back to the author rather than saved: the
 * model is a first-pass writer, not a publisher, and the editor is where a
 * human decides whether any of it is worth keeping.
 */
class PostGenerator
{
    /**
     * The API version header the Messages API requires on every request.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * Lets the provider re-serve a request its safety classifiers decline,
     * instead of the author seeing a bare refusal for an innocuous title.
     */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * The ceiling on a single response, covering reasoning and the draft.
     *
     * Sized so a long post still lands inside one non-streaming request; the
     * shape of the draft is governed by the system prompt, not by this number.
     */
    private const MAX_TOKENS = 16000;

    /**
     * The most tags a draft may suggest, matching what the post form accepts.
     */
    private const MAX_TAGS = 5;

    /**
     * The longest description the posts table will hold.
     */
    private const MAX_DESCRIPTION_CHARACTERS = 65535;

    /**
     * The shortest description the post endpoints will accept.
     */
    private const MIN_DESCRIPTION_CHARACTERS = 10;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You draft blog posts for a publishing platform. You are given only a
        title; you write the body that belongs under it.

        Write the body in Markdown, 500 to 900 words, using `##` subheadings and
        short paragraphs. Open with the substance of the topic rather than
        restating the title, and do not repeat the title as a heading. Close by
        landing the point, not by announcing that you are concluding.

        Write as a knowledgeable practitioner: concrete, specific, and free of
        filler. Do not invent statistics, quotations, dates, or named sources —
        if a claim would need a citation, make the point without it.

        Also suggest up to five short topic tags, lowercase, one or two words
        each, covering what the post is actually about.

        Return only the JSON object the schema describes.
        PROMPT;

    /**
     * Write a draft body and tag list for the given title.
     *
     * @return array{description: string, tags: array<int, string>}
     *
     * @throws PostGenerationFailed
     */
    public function generate(string $title): array
    {
        $response = $this->send($title);

        if ($response->failed()) {
            Log::warning('The AI provider rejected a post drafting request.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            throw PostGenerationFailed::providerRejected();
        }

        return $this->parse($response, $title);
    }

    /**
     * Ask the provider for a draft.
     *
     * @throws PostGenerationFailed
     */
    private function send(string $title): Response
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw PostGenerationFailed::notConfigured();
        }

        try {
            return $this->client($key)->post('/v1/messages', [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => self::MAX_TOKENS,
                'system' => self::SYSTEM_PROMPT,
                'fallbacks' => 'default',
                'output_config' => [
                    'effort' => (string) config('services.anthropic.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => $this->responseSchema()],
                ],
                'messages' => [
                    ['role' => 'user', 'content' => 'Title: '.$title],
                ],
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach the AI provider while drafting a post.', [
                'exception' => $exception->getMessage(),
            ]);

            throw PostGenerationFailed::unreachable($exception);
        }
    }

    /**
     * Build the HTTP client used to talk to the Messages API.
     */
    private function client(string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.anthropic.base_url'), '/'))
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => self::FALLBACK_BETA,
            ])
            ->timeout((int) config('services.anthropic.timeout'))
            ->retry(2, 500, $this->shouldRetry(...), throw: false)
            ->asJson()
            ->acceptJson();
    }

    /**
     * Whether a failed attempt is worth repeating.
     *
     * Only transient faults are retried. A 4xx means the request itself is
     * wrong, and sending it again would just spend money to fail identically.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->response->serverError()
            || $exception->response->status() === 429;
    }

    /**
     * Turn the provider's answer into a draft the post form can hold.
     *
     * @return array{description: string, tags: array<int, string>}
     *
     * @throws PostGenerationFailed
     */
    private function parse(Response $response, string $title): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $stopReason = $payload['stop_reason'] ?? null;

        // Checked before the content is touched: a refused response carries an
        // empty or partial `content`, so reading it first would surface the
        // decline as an unrelated parsing failure.
        if ($stopReason === 'refusal') {
            Log::info('The AI provider declined to draft a post.', [
                'title' => $title,
                'category' => $payload['stop_details']['category'] ?? null,
            ]);

            throw PostGenerationFailed::refused();
        }

        if ($stopReason === 'max_tokens') {
            Log::warning('An AI post draft was cut short by the token ceiling.', ['title' => $title]);

            throw PostGenerationFailed::unusableResponse();
        }

        $draft = $this->decodeDraft($payload);

        $description = $this->cleanDescription($draft['description'] ?? null);

        if ($description === null) {
            Log::warning('The AI provider returned a draft with no usable body.', ['title' => $title]);

            throw PostGenerationFailed::unusableResponse();
        }

        return [
            'description' => $description,
            'tags' => $this->cleanTags($draft['tags'] ?? null),
        ];
    }

    /**
     * Pull the JSON draft out of the response's first text block.
     *
     * The block is searched for rather than indexed: a response may lead with
     * other block types, and a fallback answer always does.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PostGenerationFailed
     */
    private function decodeDraft(array $payload): array
    {
        $blocks = $payload['content'] ?? [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text' || ! is_string($block['text'] ?? null)) {
                continue;
            }

            $decoded = json_decode($block['text'], true);

            if (is_array($decoded)) {
                return $decoded;
            }

            // The draft block was found but held something other than the JSON
            // the schema asked for, so later blocks will not rescue it.
            break;
        }

        throw PostGenerationFailed::unusableResponse();
    }

    /**
     * Trim the drafted body and reject one too short to be worth showing.
     */
    private function cleanDescription(mixed $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $description = trim($description);

        if (mb_strlen($description) < self::MIN_DESCRIPTION_CHARACTERS) {
            return null;
        }

        return Str::limit($description, self::MAX_DESCRIPTION_CHARACTERS, end: '');
    }

    /**
     * Reduce the suggested tags to ones the post endpoints would accept.
     *
     * Anything unusable is dropped rather than reported: these are suggestions
     * the author has not asked for and can freely edit, so a malformed one is
     * noise, not a validation failure.
     *
     * @return array<int, string>
     */
    private function cleanTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        /** @var array<string, string> $kept keyed by lowercase tag, so casing variants collapse */
        $kept = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = Str::squish($tag);
            $length = mb_strlen($tag);

            if ($length < 2 || $length > 50 || isset($kept[Str::lower($tag)])) {
                continue;
            }

            $kept[Str::lower($tag)] = $tag;

            if (count($kept) === self::MAX_TAGS) {
                break;
            }
        }

        return array_values($kept);
    }

    /**
     * The configured API key, or an empty string when there is none.
     */
    private function apiKey(): string
    {
        return trim((string) config('services.anthropic.key'));
    }

    /**
     * The shape the model must answer in.
     *
     * Length and count limits are enforced here in PHP instead: the schema
     * dialect the API accepts ignores `minLength`, `maxItems`, and friends.
     *
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'The full body of the post, in Markdown.',
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => 'Up to five short, lowercase topic tags.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['description', 'tags'],
            'additionalProperties' => false,
        ];
    }
}
