<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(6);

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->paragraphs(3, true),
            'image_path' => null,
            'image_thumbnail_path' => null,
        ];
    }

    /**
     * Indicate that the post carries an uploaded image.
     */
    public function withImage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'image_path' => 'posts/images/'.Str::random(40).'.jpg',
        ]);
    }
}
