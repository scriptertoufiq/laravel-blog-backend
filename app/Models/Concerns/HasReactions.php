<?php

namespace App\Models\Concerns;

use App\Enums\ReactionType;
use App\Models\Reaction;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Gives a model reactions that readers can leave on it.
 *
 * @phpstan-require-extends Model
 */
trait HasReactions
{
    /**
     * Clear a model's reactions when it goes.
     *
     * A polymorphic key cannot carry a foreign key constraint, so the database
     * will not cascade this for us and the rows would otherwise be orphaned.
     */
    protected static function bootHasReactions(): void
    {
        static::deleting(function (self $model): void {
            $model->reactions()->delete();
        });
    }

    /**
     * Every reaction left on this model.
     *
     * @return MorphMany<Reaction, $this>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    /**
     * The reaction left by one particular reader.
     *
     * Unconstrained on its own — callers narrow it to the reader they care
     * about through `withViewerReaction` or `loadReactionState`. A person holds
     * at most one reaction per model, so at most one row ever matches.
     *
     * @return MorphOne<Reaction, $this>
     */
    public function viewerReaction(): MorphOne
    {
        return $this->morphOne(Reaction::class, 'reactable');
    }

    /**
     * Load one aggregate column per reaction type, e.g. `reactions_like_count`.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withReactionCounts(Builder $query): void
    {
        $query->withCount(static::reactionCountAggregates());
    }

    /**
     * Eager load the given reader's own reaction, so a listing can show which
     * button is pressed without spending a query per row.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withViewerReaction(Builder $query, ?User $viewer): void
    {
        $query->with(static::viewerReactionEagerLoad($viewer));
    }

    /**
     * Pull both the counts and the reader's own reaction onto a model that has
     * already been fetched, such as one just written.
     */
    public function loadReactionState(?User $viewer): static
    {
        $this->loadCount(static::reactionCountAggregates());
        $this->load(static::viewerReactionEagerLoad($viewer));

        return $this;
    }

    /**
     * The reaction this model was loaded for, or null when it was loaded for
     * nobody in particular.
     *
     * Reports null rather than querying when the relation was never loaded,
     * which keeps a resource rendering a long list from firing a query a row.
     */
    public function viewerReactionType(): ?ReactionType
    {
        if (! $this->relationLoaded('viewerReaction')) {
            return null;
        }

        return $this->viewerReaction?->type;
    }

    /**
     * The per-type `withCount` aggregates, keyed by the alias they load under.
     *
     * @return array<string, callable(Builder<Reaction>): Builder<Reaction>>
     */
    protected static function reactionCountAggregates(): array
    {
        $aggregates = [];

        foreach (ReactionType::cases() as $type) {
            $aggregates['reactions as '.$type->countAttribute()] = fn (Builder $reactions): Builder => $reactions
                ->where('type', $type->value);
        }

        return $aggregates;
    }

    /**
     * The eager load that narrows `viewerReaction` to one reader.
     *
     * A guest is still loaded, against a user id that cannot exist, so callers
     * read an empty relation instead of falling back to a lazy query.
     *
     * @return array<string, Closure(Relation<*, *, *>): mixed>
     */
    protected static function viewerReactionEagerLoad(?User $viewer): array
    {
        $readerId = $viewer?->getKey() ?? 0;

        return [
            'viewerReaction' => fn (Relation $reaction) => $reaction->where('user_id', $readerId),
        ];
    }
}
