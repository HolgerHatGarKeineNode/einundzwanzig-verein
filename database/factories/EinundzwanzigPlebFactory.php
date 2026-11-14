<?php

namespace Database\Factories;

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EinundzwanzigPleb>
 */
class EinundzwanzigPlebFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pubkey' => $this->faker->sha256(),
            'npub' => $this->faker->word(),
            'email' => $this->faker->safeEmail(),
            'association_status' => AssociationStatus::DEFAULT,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'association_status' => AssociationStatus::ACTIVE,
        ]);
    }

    public function boardMember(): static
    {
        return $this->state(fn (array $attributes) => [
            'npub' => config('einundzwanzig.config.current_board')[0],
            'association_status' => AssociationStatus::HONORARY,
        ]);
    }

    public function withPaidYear(int $year): static
    {
        return $this->afterCreating(function (EinundzwanzigPleb $pleb) use ($year) {
            $pleb->paymentEvents()->create([
                'year' => $year,
                'amount' => 21000,
                'paid' => true,
                'event_id' => 'test_event_'.fake()->sha256(),
            ]);
        });
    }

    public function withPaidCurrentYear(): static
    {
        return $this->afterCreating(function (EinundzwanzigPleb $pleb) {
            $pleb->paymentEvents()->create([
                'year' => date('Y'),
                'amount' => 21000,
                'paid' => true,
                'event_id' => 'test_event_'.fake()->sha256(),
            ]);
        });
    }
}
