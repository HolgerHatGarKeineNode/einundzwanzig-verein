<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Exceptions\ElectionActionRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The way of a candidacy: a member submits it for themself; it is approved,
 * rejected or withdrawn; from the opening of the round on, it is frozen
 * (`ElectionCandidate` refuses every change then).
 *
 * pending → approved | rejected; pending | approved → withdrawn.
 *
 * Who may decide is `ElectionPolicy`'s answer, asked on the locked, current
 * row inside the transaction that changes it. Every decision writes an audit
 * line in the same transaction; the submission itself is the member's own
 * act and writes none.
 */
class Candidacies
{
    use AuthorizesElectionActor;

    public function __construct(protected ElectoralRoll $roll, protected ElectionAuditLog $audit) {}

    /**
     * A member stands in a draft round. The fee need not be paid yet — the
     * approval waits for it (E14).
     *
     * @throws AuthorizationException
     */
    public function submit(ElectionRound $round, EinundzwanzigPleb $member, ?string $statement = null): ElectionCandidate
    {
        return DB::transaction(function () use ($round, $member, $statement): ElectionCandidate {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());

            $this->authorizeActor($member, 'submitCandidacy', $round);

            return ElectionCandidate::query()->create([
                'election_round_id' => $round->getKey(),
                'einundzwanzig_pleb_id' => $member->getKey(),
                'status' => ElectionCandidateStatus::Pending,
                'statement' => $statement,
            ]);
        });
    }

    /**
     * Put a candidacy on the ballot. Refused while the candidate is not
     * entitled to vote under the election's rule — standing presupposes the
     * right to vote, and that presupposes the paid fee.
     *
     * @throws AuthorizationException
     * @throws ElectionActionRefusedException
     */
    public function approve(ElectionCandidate $candidate, EinundzwanzigPleb $actor): ElectionCandidate
    {
        return $this->decide($candidate, $actor, 'approveCandidacy', ElectionCandidateStatus::Approved, ElectionAuditAction::CandidacyApproved, null);
    }

    /**
     * @throws AuthorizationException
     */
    public function reject(ElectionCandidate $candidate, EinundzwanzigPleb $actor, string $reason): ElectionCandidate
    {
        return $this->decide($candidate, $actor, 'rejectCandidacy', ElectionCandidateStatus::Rejected, ElectionAuditAction::CandidacyRejected, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function withdraw(ElectionCandidate $candidate, EinundzwanzigPleb $actor, string $reason): ElectionCandidate
    {
        return $this->decide($candidate, $actor, 'withdrawCandidacy', ElectionCandidateStatus::Withdrawn, ElectionAuditAction::CandidacyWithdrawn, $reason);
    }

    /**
     * The member takes back their own candidacy. Written to the audit log
     * like the board's withdrawal, with the member as the actor.
     *
     * @throws AuthorizationException
     */
    public function withdrawOwn(ElectionCandidate $candidate, EinundzwanzigPleb $member): ElectionCandidate
    {
        return $this->decide($candidate, $member, 'withdrawOwnCandidacy', ElectionCandidateStatus::Withdrawn, ElectionAuditAction::CandidacyWithdrawn, 'Vom Mitglied selbst zurückgezogen');
    }

    /**
     * @throws AuthorizationException
     * @throws ElectionActionRefusedException
     */
    protected function decide(
        ElectionCandidate $candidate,
        EinundzwanzigPleb $actor,
        string $ability,
        ElectionCandidateStatus $to,
        ElectionAuditAction $action,
        ?string $reason,
    ): ElectionCandidate {
        return DB::transaction(function () use ($candidate, $actor, $ability, $to, $action, $reason): ElectionCandidate {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($candidate->election_round_id);
            $candidate = ElectionCandidate::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();

            $this->authorizeActor($actor, $ability, $candidate);

            $pleb = $candidate->pleb;

            if ($to === ElectionCandidateStatus::Approved
                && ($pleb === null || $this->roll->reasonFor($round->election, $pleb, now()) === null)) {
                throw new ElectionActionRefusedException('A candidacy is approved only once the candidate is entitled to vote in this election.');
            }

            $candidate->status = $to;
            $candidate->save();

            $this->audit->append(
                $round->election,
                $action,
                $actor,
                round: $round,
                subject: $pleb,
                details: array_filter(['candidate_id' => (int) $candidate->getKey(), 'reason' => $reason], fn (mixed $value): bool => $value !== null),
            );

            return $candidate;
        });
    }
}
