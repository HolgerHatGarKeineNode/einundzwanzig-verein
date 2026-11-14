<?php

namespace Database\Factories;

use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ElectionReceipt>
 */
class ElectionReceiptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_round_id' => ElectionRound::factory(),
            'hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
