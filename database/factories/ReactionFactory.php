<?php

namespace Database\Factories;

use App\Enums\ReactionType;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reactable_type' => Post::class,
            'reactable_id' => Post::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(ReactionType::cases()),
        ];
    }

    /**
     * Point the reaction at a particular post or comment.
     */
    public function forReactable(Model $reactable): static
    {
        return $this->state([
            'reactable_type' => $reactable->getMorphClass(),
            'reactable_id' => $reactable->getKey(),
        ]);
    }

    /**
     * Leave a specific reaction rather than a random one.
     */
    public function ofType(ReactionType $type): static
    {
        return $this->state(['type' => $type]);
    }

    /**
     * Attach the reaction to a comment instead of the default post.
     */
    public function onComment(): static
    {
        return $this->state([
            'reactable_type' => Comment::class,
            'reactable_id' => Comment::factory(),
        ]);
    }
}
