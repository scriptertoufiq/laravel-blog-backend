<?php

namespace App\Services\Blog;

use App\Enums\ReactionType;
use App\Models\Reactable;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Records reactions and reports how a post or comment currently stands.
 *
 * @phpstan-type ReactionSummary array{
 *     total: int,
 *     counts: array<string, int>,
 *     viewer_reaction: string|null,
 * }
 */
class ReactionService
{
    /**
     * Set the reader's reaction, replacing any earlier one.
     *
     * `updateOrCreate` rather than a read-then-write: it recovers from the
     * unique index firing, so a double click or two open tabs settle on one row
     * instead of surfacing a database error.
     *
     * @return ReactionSummary
     */
    public function react(Model&Reactable $reactable, User $reader, ReactionType $type): array
    {
        $reactable->reactions()->updateOrCreate(
            ['user_id' => $reader->getKey()],
            ['type' => $type],
        );

        return $this->summarize($reactable, $reader);
    }

    /**
     * Take back the reader's reaction, if they left one.
     *
     * Clearing a reaction that was never there is not an error — the client and
     * the database agree on the outcome either way.
     *
     * @return ReactionSummary
     */
    public function unreact(Model&Reactable $reactable, User $reader): array
    {
        $reactable->reactions()->where('user_id', $reader->getKey())->delete();

        return $this->summarize($reactable, $reader);
    }

    /**
     * Read the current tally for one post or comment.
     *
     * @return ReactionSummary
     */
    public function summarize(Model&Reactable $reactable, ?User $viewer = null): array
    {
        $counts = $reactable->reactions()
            ->groupBy('type')
            ->selectRaw('type, count(*) as aggregate')
            ->pluck('aggregate', 'type');

        $viewerReaction = $viewer === null ? null : $reactable->reactions()
            ->where('user_id', $viewer->getKey())
            ->value('type');

        return $this->summary(
            $counts->map(fn (mixed $count): int => (int) $count)->all(),
            $viewerReaction instanceof ReactionType ? $viewerReaction->value : null,
        );
    }

    /**
     * Build a summary from counts already loaded by the `withReactionCounts`
     * scope, spending no further queries.
     *
     * @return ReactionSummary
     */
    public function summarizeLoaded(Model&Reactable $reactable, ?ReactionType $viewerReaction): array
    {
        $counts = [];

        foreach (ReactionType::cases() as $type) {
            $counts[$type->value] = (int) ($reactable->getAttribute($type->countAttribute()) ?? 0);
        }

        return $this->summary($counts, $viewerReaction?->value);
    }

    /**
     * Count reactions per type for a set of targets, in one query.
     *
     * @param  array<int, int>  $reactableIds
     * @return array<int, array<string, int>> target id => counts keyed by type
     */
    public function countsFor(string $reactableType, array $reactableIds): array
    {
        if ($reactableIds === []) {
            return [];
        }

        return Reaction::query()
            ->where('reactable_type', $reactableType)
            ->whereIn('reactable_id', $reactableIds)
            ->groupBy('reactable_id', 'type')
            ->selectRaw('reactable_id, type, count(*) as aggregate')
            ->get()
            ->groupBy('reactable_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn (Reaction $row): array => [
                    $row->type->value => (int) $row->getAttribute('aggregate'),
                ])
                ->all())
            ->all();
    }

    /**
     * Look up which reaction a reader left on each of a set of targets.
     *
     * Used to personalise payloads that were rendered — and cached — without
     * knowing who would eventually read them.
     *
     * @param  array<int, int>  $reactableIds
     * @return array<int, string> target id => reaction value
     */
    public function viewerReactionsFor(string $reactableType, array $reactableIds, ?User $viewer): array
    {
        if ($viewer === null || $reactableIds === []) {
            return [];
        }

        return Reaction::query()
            ->where('reactable_type', $reactableType)
            ->whereIn('reactable_id', $reactableIds)
            ->where('user_id', $viewer->getKey())
            ->pluck('type', 'reactable_id')
            ->map(fn (ReactionType $type): string => $type->value)
            ->all();
    }

    /**
     * Fill in the types nobody picked, and total the rest.
     *
     * Every type is always reported, zeros included, so a client can draw the
     * whole picker from the payload alone.
     *
     * @param  array<string, int>  $counts
     * @return ReactionSummary
     */
    public function summary(array $counts = [], ?string $viewerReaction = null): array
    {
        $complete = [];

        foreach (ReactionType::cases() as $type) {
            $complete[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return [
            'total' => array_sum($complete),
            'counts' => $complete,
            'viewer_reaction' => $viewerReaction,
        ];
    }
}
