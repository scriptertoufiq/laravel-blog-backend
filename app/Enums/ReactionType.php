<?php

namespace App\Enums;

/**
 * The reactions a reader may leave on a post or a comment.
 *
 * A reader holds at most one of these per thing they react to, so switching
 * from `Like` to `Love` replaces the old reaction rather than adding to it.
 */
enum ReactionType: string
{
    case Like = 'like';

    case Love = 'love';

    case Laugh = 'laugh';

    case Insightful = 'insightful';

    case Sad = 'sad';

    /**
     * The emoji the clients render for this reaction.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍',
            self::Love => '❤️',
            self::Laugh => '😂',
            self::Insightful => '💡',
            self::Sad => '😢',
        };
    }

    /**
     * The short label shown beside the emoji.
     */
    public function label(): string
    {
        return match ($this) {
            self::Like => __('Like'),
            self::Love => __('Love'),
            self::Laugh => __('Haha'),
            self::Insightful => __('Insightful'),
            self::Sad => __('Sad'),
        };
    }

    /**
     * The alias a per-type `withCount` aggregate is loaded under.
     */
    public function countAttribute(): string
    {
        return 'reactions_'.$this->value.'_count';
    }

    /**
     * Every backing value, in declaration order.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
