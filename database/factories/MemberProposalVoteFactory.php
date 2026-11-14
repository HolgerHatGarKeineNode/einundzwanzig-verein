<?php

namespace Database\Factories;

use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberProposalVote>
 */
class MemberProposalVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_proposal_id' => MemberProposal::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory(),
            'value' => MemberProposalVote::UP,
        ];
    }

    public function up(): static
    {
        return $this->state(fn () => ['value' => MemberProposalVote::UP]);
    }

    public function down(): static
    {
        return $this->state(fn () => ['value' => MemberProposalVote::DOWN]);
    }
}
