<?php

namespace Database\Factories;

use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlebDmRelay>
 */
class PlebDmRelayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory(),
            'relays' => ['wss://inbox.example.com/'],
            'event_id' => bin2hex(random_bytes(32)),
            'event_created_at' => fake()->numberBetween(1_700_000_000, 1_760_000_000),
        ];
    }
}
