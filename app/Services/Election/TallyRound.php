<?php

namespace App\Services\Election;

use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionRound;

/**
 * The count of a sealed round.
 *
 * Reads the ballot rows through `ElectionRound::sealedChoices()` and nowhere
 * else, so there is no count of a round that is open, merely over by the
 * clock, or annulled.
 */
class TallyRound
{
    public function __construct(protected Tally $tally) {}

    /**
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function handle(ElectionRound $round): TallyResult
    {
        $rows = $round->sealedChoices()
            ->toBase()
            ->get(['election_candidate_id', 'choice'])
            ->map(fn (object $row): array => [
                'candidate' => $row->election_candidate_id === null ? null : (int) $row->election_candidate_id,
                'choice' => (string) $row->choice,
            ]);

        return $this->tally->count(
            $round->counting_rule,
            $round->seats,
            $round->candidates()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $rows,
        );
    }
}
