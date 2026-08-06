<?php

namespace Database\Factories;

use App\Models\SecurityAttempt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Validation\ValidationException;

/**
 * @extends Factory<SecurityAttempt>
 */
class SecurityAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'method' => fake()->randomElement(['GET', 'POST']),
            'url' => fake()->url(),
            'route_name' => fake()->randomElement(['association.profile', 'association.news', 'association.projectSupport']),
            'exception_class' => fake()->randomElement([
                ValidationException::class,
                AuthenticationException::class,
                AuthorizationException::class,
            ]),
            'exception_message' => fake()->sentence(),
            'component_name' => fake()->randomElement(['association.profile', 'association.project-support.show']),
            'target_property' => fake()->randomElement(['name', 'email', 'npub']),
            'payload' => ['attempt' => fake()->word()],
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
        ];
    }

    public function high(): static
    {
        return $this->state(fn () => ['severity' => 'high']);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }
}
