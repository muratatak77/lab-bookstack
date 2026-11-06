<?php

namespace Database\Factories\Search;

use BookStack\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\BookStack\Search\SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    protected $model = \BookStack\Search\SavedSearch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'query' => $this->faker->word() . ' {type:page} [category=docs]',
        ];
    }
}
