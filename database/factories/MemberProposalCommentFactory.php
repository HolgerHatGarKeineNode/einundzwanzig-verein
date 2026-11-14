<?php

namespace Database\Factories;

use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberProposalComment>
 */
class MemberProposalCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_proposal_id' => MemberProposal::factory(),
            'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory(),
            'body' => $this->faker->sentence(),
            'is_hidden' => false,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_hidden' => true]);
    }
}
