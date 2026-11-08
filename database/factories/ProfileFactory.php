<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->firstName(), 'is_kid' => false];
    }

    public function kid(int $maxAge = 7): static
    {
        return $this->state(['is_kid' => true, 'max_age_rating' => $maxAge]);
    }
}
