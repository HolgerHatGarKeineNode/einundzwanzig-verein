<?php

namespace App\Services\Election;

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use UnexpectedValueException;

/**
 * The count of a sealed round, from its stored votes.
 *
 * Refuses until the round is sealed, so there is no count of a round that is
 * open, merely over by the clock, or annulled. Asked on a fresh row, not on
 * the instance it is given, so a stale or hand-built model cannot talk its
 * way past the check.
 */
class TallyRound
{
    public function __construct(protected Tally $tally) {}

    /**
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function handle(ElectionRound $round): TallyResult
    {
        $round = $round->exists ? ElectionRound::query()->find($round->getKey()) : null;

        if ($round === null || ! $round->isSealed()) {
            throw new ElectionRoundStateException('The votes of a round cannot be counted before it is sealed.');
        }

        return $this->count($round, $round->votes()->pluck('choices')->all());
    }

    /**
     * The effective result of a sealed round: the count, with the chair's
     * casting vote applied if one is recorded for its tie. Everything that
     * says who is elected or whether a round is decided reads this, not
     * `handle()`; the manifest and `election:verify` keep the raw count.
     *
     * A recorded decision that does not fit the count (a broken or
     * hand-edited row) is not applied: the round stays tied, nobody is
     * elected by it.
     *
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function effective(ElectionRound $round): TallyResult
    {
        $result = $this->handle($round);

        if ($result->outcome !== ElectionOutcome::Tie) {
            return $result;
        }

        $castingVote = ElectionCastingVote::query()->where('election_round_id', $round->getKey())->first();

        if ($castingVote === null) {
            return $result;
        }

        $decision = $castingVote->decision($result->tied, $result->tiedSeats);

        return $decision->fits($result) ? $result->decided($decision) : $result;
    }

    /**
     * Count `$selections` (each candidate id => choice value, empty for a
     * blank vote) by the rule and against the candidates of `$round` who
     * were on its ballot: the approved candidacies, as `CastBallot` and
     * `election:verify` take them. A pending, rejected or withdrawn
     * candidacy is in no result, not even as zero.
     *
     * A selection that names such a candidacy does not fit the round, like
     * any other corrupted vote: the round is not counted at all, rather
     * than counted without that vote.
     *
     * @param  iterable<mixed>  $selections
     *
     * @throws UnexpectedValueException when a selection does not fit the round — a corrupted vote is not counted
     */
    public function count(ElectionRound $round, iterable $selections): TallyResult
    {
        $rows = [];

        foreach ($selections as $selection) {
            if (! is_array($selection)) {
                throw new UnexpectedValueException('A vote does not fit the rule or the candidates of its round.');
            }

            if ($selection === []) {
                $rows[] = ['candidate' => null, 'choice' => 'blank'];
            }

            foreach ($selection as $candidate => $choice) {
                $rows[] = ['candidate' => (int) $candidate, 'choice' => (string) $choice];
            }
        }

        return $this->tally->count(
            $round->counting_rule,
            $round->seats,
            $round->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $rows,
        );
    }
}
