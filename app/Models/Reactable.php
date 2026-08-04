<?php

namespace App\Models;

use App\Enums\ReactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A model readers can leave reactions on.
 *
 * Satisfied by pairing this with the `HasReactions` trait, which supplies every
 * member. Type hints are written as `Model&Reactable` so the reaction code can
 * reach for these and the usual Eloquent methods alike.
 *
 * @phpstan-require-extends Model
 */
interface Reactable
{
    /**
     * Every reaction left on this model.
     *
     * @return MorphMany<Reaction, covariant Model>
     */
    public function reactions(): MorphMany;

    /**
     * The reaction left by one particular reader.
     *
     * @return MorphOne<Reaction, covariant Model>
     */
    public function viewerReaction(): MorphOne;

    /**
     * Pull the per-type counts and one reader's own reaction onto the model.
     */
    public function loadReactionState(?User $viewer): static;

    /**
     * The reaction this model was loaded for, if it was loaded for anyone.
     */
    public function viewerReactionType(): ?ReactionType;
}
