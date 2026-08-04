<?php

namespace App\Models;

use App\Models\Concerns\HasReactions;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property string|null $image_path
 * @property string|null $image_thumbnail_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'slug', 'description', 'image_path'])]
class Post extends Model implements Reactable
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasReactions;

    /**
     * Clear the reactions on this post's comments before it is deleted.
     *
     * The comments themselves go with the post through a database cascade,
     * which fires no Eloquent events — so nothing else is left to take their
     * reactions with them.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $post): void {
            Reaction::query()
                ->where('reactable_type', (new Comment)->getMorphClass())
                ->whereIn('reactable_id', $post->comments()->select('id'))
                ->delete();
        });
    }

    /**
     * The author of the post.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The tags attached to the post.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * The comments left on the post.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
