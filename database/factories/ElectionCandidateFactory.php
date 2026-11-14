<?php

namespace Database\Factories;

use App\Enums\ElectionCandidateStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An APPROVED candidate, so a round built from the factory has a ballot.
 * Candidacies that still need a decision come from `pending()` or from
 * `Candidacies::submit()`.
 *
 * @extends Factory<ElectionCandidate>
 */
class ElectionCandidateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'election_round_id' => ElectionRound::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->active(),
            'status' => ElectionCandidateStatus::Approved,
        ];
    }

    public function status(ElectionCandidateStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function pending(): static
    {
        return $this->status(ElectionCandidateStatus::Pending);
    }
}
