<?php

namespace Database\Factories;

use App\Enums\ElectionChoice;
use App\Models\ElectionBallotChoice;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A blank ballot row by default: the only kind that needs no candidate.
 *
 * @extends Factory<ElectionBallotChoice>
 */
class ElectionBallotChoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_round_id' => ElectionRound::factory(),
            'election_candidate_id' => null,
            'choice' => ElectionChoice::Blank,
        ];
    }
}
