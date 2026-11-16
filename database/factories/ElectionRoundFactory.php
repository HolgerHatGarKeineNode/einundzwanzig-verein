<?php

namespace Database\Factories;

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A DRAFT round. Opening, closing and voting go through the actions in
 * `App\Services\Election` — the factory does not fake those states.
 *
 * @extends Factory<ElectionRound>
 */
class ElectionRoundFactory extends Factory
{
    private static int $position = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_event_id' => Election::factory(),
            'position' => ++self::$position,
            'title' => fake()->words(2, true),
            'mode' => ElectionRoundMode::Live,
            'counting_rule' => ElectionCountingRule::SingleMajority,
            'seats' => 1,
        ];
    }

    public function window(CarbonInterface $opensAt, CarbonInterface $closesAt): static
    {
        return $this->state(fn () => [
            'mode' => ElectionRoundMode::Window,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ]);
    }

    public function countingRule(ElectionCountingRule $rule, int $seats = 1): static
    {
        return $this->state(fn () => [
            'counting_rule' => $rule,
            'seats' => $seats,
        ]);
    }

    public function withCandidates(int $count = 2): static
    {
        return $this->afterCreating(function (ElectionRound $round) use ($count): void {
            ElectionCandidate::factory()->count($count)->create(['election_round_id' => $round->getKey()]);
        });
    }
}
