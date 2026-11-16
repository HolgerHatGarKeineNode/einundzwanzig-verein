<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

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
 * Never for a candidate elected in another round of the election, nor for
 * one standing in another round of the election that is opened and not
 * sealed yet.
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

    public const MAX_CHAIR_LENGTH = 60;

    public function __construct(protected TallyRound $tally, protected ElectionAuditLog $audit) {}

    /**
     * @param  list<int>  $candidateIds  the candidates of the round the chair decided for
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException when the round is not sealed
     * @throws ElectionActionRefusedException when the round has no undecided tie or the decision does not fit it
     * @throws ElectionCandidateBlockedException when the chair decided for a candidate elected in another round
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

            $this->assertNoneElectedElsewhere($round, $chosen);

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

    /**
     * Nobody is elected twice (Wahlreglement § 3 Nr. 7): the chair cannot
     * decide for a candidate whose member is elected in another sealed round
     * of the election, by its count or by a casting vote there.
     *
     * This never leaves the tie undecidable by itself: a tie always has more
     * level candidates than tied seats, so with one of them elected elsewhere
     * the chair names the others, and on a yes = no tie the chair may decide
     * „nein“. `OpenRound` refuses a round while one of its candidates is level
     * in an undecided tie elsewhere, but that refusal does not cover every
     * path: a tie whose run-off is a living draft does not block, so A can be
     * elected elsewhere, withdrawn from the run-off, and the run-off annulled,
     * which hands the decision back to the chair with A elected elsewhere
     * (gate j2g round 2, probe P1b). This check is the one that stops it.
     *
     * The same path leaves A standing in an opened round of another contest
     * that is not sealed yet: nobody knows whether A is elected there, and
     * sealing it after the casting vote would elect A twice (gate j2i, D2).
     * So the chair cannot decide for a member who stands as an approved
     * candidate in an opened, unsealed, non-annulled round of the election
     * either: fail closed, the chair names another candidate level in the tie
     * or waits until that round is sealed. A draft does not count — once the
     * casting vote elects A, `OpenRound` refuses a draft A stands in.
     *
     * A sealed round whose stored votes cannot be counted cannot say who is
     * elected there: the casting vote is refused, never recorded on a guess.
     *
     * @param  list<int>  $chosen  candidate ids of `$round`
     *
     * @throws ElectionCandidateBlockedException
     * @throws ElectionActionRefusedException
     */
    protected function assertNoneElectedElsewhere(ElectionRound $round, array $chosen): void
    {
        $members = $round->candidates()->whereKey($chosen)->pluck('einundzwanzig_pleb_id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($members === []) {
            return;
        }

        $others = ElectionRound::query()
            ->where('election_event_id', $round->election_event_id)
            ->whereKeyNot($round->getKey())
            ->whereNotNull('closed_at')
            ->whereNull('annulled_at')
            ->whereHas('candidates', fn (Builder $query): Builder => $query->whereIn('einundzwanzig_pleb_id', $members))
            ->with('candidates')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($others as $other) {
            try {
                $elected = $this->tally->effective($other)->elected;
            } catch (UnexpectedValueException) {
                throw new ElectionActionRefusedException(sprintf('The stored votes of the round "%s", which shares a candidate with this tie, cannot be counted; check it with election:verify.', $other->title));
            }

            $candidate = $other->candidates
                ->filter(fn (ElectionCandidate $candidate): bool => in_array((int) $candidate->einundzwanzig_pleb_id, $members, true) && in_array((int) $candidate->getKey(), $elected, true))
                ->sortBy('id')
                ->first();

            if ($candidate !== null) {
                throw new ElectionCandidateBlockedException(sprintf('The chair cannot decide for a candidate who is elected in the round "%s" of the election already; decide for another candidate level in the tie.', $other->title), (int) $candidate->einundzwanzig_pleb_id, $other->title);
            }
        }

        $undecided = ElectionRound::query()
            ->where('election_event_id', $round->election_event_id)
            ->whereKeyNot($round->getKey())
            ->whereNotNull('opened_at')
            ->whereNull('closed_at')
            ->whereNull('annulled_at')
            ->whereHas('candidates', fn (Builder $query): Builder => $query->whereIn('einundzwanzig_pleb_id', $members)->where('status', ElectionCandidateStatus::Approved))
            ->with('candidates')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($undecided !== null) {
            $candidate = $undecided->candidates
                ->filter(fn (ElectionCandidate $candidate): bool => in_array((int) $candidate->einundzwanzig_pleb_id, $members, true) && $candidate->status === ElectionCandidateStatus::Approved)
                ->sortBy('id')
                ->first();

            throw new ElectionCandidateBlockedException(sprintf('The chair cannot decide for a candidate who also stands in the opened round "%s" of the election, which is not sealed, so nobody knows yet whether they are elected there; decide for another candidate level in the tie, or seal that round first.', $undecided->title), (int) $candidate?->einundzwanzig_pleb_id, $undecided->title);
        }
    }
}
