<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionOutcome;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Enter the chair's casting vote on the tie of a sealed round: „Bei
 * Stimmengleichheit fällt die/der Vorsitzende den Stichentscheid.“
 * (statutes Art. 6). The election officer records it; the application never
 * decides a tie itself.
 *
 *  - a tie between candidates: exactly as many of the tied as there are tied seats;
 *  - a tie of yes = no: any of the tied up to the tied seats, none included —
 *    the chair may decide „nein“.
 *
 * Recorded once and never changed. Casting vote and run-off exclude each
 * other: no casting vote while a run-off of the round stands, and
 * `CreateRunOff` refuses one after a casting vote. A tie in a run-off is
 * decided the same way, which ends the chain. Annulling the round voids the
 * decision (`TallyRound::effective()` counts sealed rounds only).
 */
class RecordCastingVote
{
    use AuthorizesElectionActor;

    public const MAX_CHAIR_LENGTH = 200;

    public function __construct(protected TallyRound $tally, protected ElectionAuditLog $audit) {}

    /**
     * @param  list<int>  $candidateIds  the candidates of the round the chair decided for
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException when the round is not sealed
     * @throws ElectionActionRefusedException when the round has no undecided tie or the decision does not fit it
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $actor, array $candidateIds, string $chair, ?string $note = null): ElectionCastingVote
    {
        return DB::transaction(function () use ($round, $actor, $candidateIds, $chair, $note): ElectionCastingVote {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $election = $round->election;

            $this->authorizeActor($actor, 'update', $election);

            if ($round->annulled_at !== null) {
                throw new ElectionActionRefusedException('An annulled round takes no casting vote.');
            }

            $result = $this->tally->handle($round);

            if ($result->outcome !== ElectionOutcome::Tie) {
                throw new ElectionActionRefusedException('The result of this round is not a tie.');
            }

            if (ElectionCastingVote::query()->where('election_round_id', $round->getKey())->exists()) {
                throw new ElectionActionRefusedException('The tie of this round has been decided already.');
            }

            if ($round->children()->whereNull('annulled_at')->exists()) {
                throw new ElectionActionRefusedException('This round has a run-off: the assembly resolved one instead of the casting vote.');
            }

            $chair = trim($chair);
            $note = $note === null || trim($note) === '' ? null : trim($note);

            if ($chair === '' || mb_strlen($chair) > self::MAX_CHAIR_LENGTH) {
                throw new ElectionActionRefusedException('The minutes must name the chair, in at most '.self::MAX_CHAIR_LENGTH.' characters.');
            }

            if ($note !== null && mb_strlen($note) > ElectionAuditLog::MAX_REASON_LENGTH) {
                throw new ElectionActionRefusedException('The note may be at most '.ElectionAuditLog::MAX_REASON_LENGTH.' characters long.');
            }

            $chosen = array_map(fn (mixed $id): int => (int) $id, array_values($candidateIds));
            sort($chosen);

            if (array_diff($chosen, $result->tied) !== [] || count(array_unique($chosen)) !== count($chosen)) {
                throw new ElectionActionRefusedException('The chair can only decide for candidates who are level in the tie.');
            }

            $decision = new CastingDecision($chosen, $result->tied, $result->tiedSeats, $chair, $note, now());

            if (! $decision->fits($result)) {
                throw new ElectionActionRefusedException('The casting vote names the wrong number of candidates for the tied seats.');
            }

            $castingVote = ElectionCastingVote::query()->create([
                'election_round_id' => $round->getKey(),
                'candidate_ids' => $chosen,
                'chair_name' => $chair,
                'note' => $note,
                'recorded_by_pleb_id' => $actor->getKey(),
            ]);

            $this->audit->append($election, ElectionAuditAction::CastingVoteRecorded, $actor, round: $round, details: array_filter([
                'chair' => $chair,
                'decided_for' => implode(',', $chosen),
                'reason' => $note,
            ], fn (?string $value): bool => $value !== null));

            return $castingVote;
        });
    }
}
