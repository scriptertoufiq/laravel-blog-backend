<?php

namespace App\Models;

use App\Enums\ReactionType;
use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reactable_type
 * @property int $reactable_id
 * @property int $user_id
 * @property ReactionType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
/**
 * Unlike a comment, `user_id` is mass assignable here: the reaction write path
 * goes through `updateOrCreate`, which needs the owner in the attribute array
 * to recover from the unique index, and the value only ever comes from the
 * authenticated user rather than from request input.
 */
#[Fillable(['user_id', 'type'])]
class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReactionType::class,
        ];
    }

    /**
     * The post or comment the reaction was left on.
     *
     * @return MorphTo<Model, $this>
     */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The reader who left the reaction.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
