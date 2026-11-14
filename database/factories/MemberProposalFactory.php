<?php

namespace Database\Factories;

use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberProposal>
 */
class MemberProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'state' => MemberProposalState::Open,
            'is_hidden' => false,
            'last_activity_at' => now(),
        ];
    }

    public function inState(MemberProposalState $state): static
    {
        return $this->state(fn () => [
            'state' => $state,
            'state_changed_at' => now(),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_hidden' => true]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['withdrawn_at' => now()]);
    }
}
