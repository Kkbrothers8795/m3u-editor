<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Recording;
use App\Models\StreamProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecordingFactory extends Factory
{
    protected $model = Recording::class;

    public function definition(): array
    {
        $start = now()->addHours(fake()->numberBetween(1, 48));
        $end = $start->copy()->addHours(fake()->numberBetween(1, 4));

        return [
            'recordable_type' => Channel::class,
            'recordable_id' => Channel::factory(),
            'user_id' => User::factory(),
            'stream_profile_id' => StreamProfile::factory(),
            'title' => fake()->sentence(3),
            'type' => fake()->randomElement(['once', 'series', 'daily', 'weekly']),
            'status' => 'scheduled',
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'pre_padding_seconds' => 60,
            'post_padding_seconds' => 120,
            'max_retries' => 3,
        ];
    }

    public function recording(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'recording',
            'actual_start' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'actual_start' => now()->subHours(2),
            'actual_end' => now(),
            'duration_seconds' => 7200,
            'file_size_bytes' => fake()->numberBetween(1000000000, 5000000000),
            'output_path' => '/path/to/recording.ts',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'last_error' => 'Failed to connect to stream',
            'retry_count' => fake()->numberBetween(0, 3),
        ]);
    }
}
