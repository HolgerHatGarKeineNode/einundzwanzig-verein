<?php

namespace App\Services\Election;

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The Wahlleitung puts a member on the roll by hand, with a reason that goes
 * into the audit log: on the roll of an open round (`handle()`), or — before
 * a round opens — as entitled in the whole election (`entitle()`).
 *
 * Only for an active or honorary member — a category without a vote does not
 * get one by hand. The fee is the Wahlleitung's judgement; that is what the
 * reason is for.
 */
class EnrolByHand
{
    use AuthorizesElectionActor;

    public function __construct(protected ElectoralRoll $roll, protected ElectionAuditLog $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     * @throws ElectionActionRefusedException
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $member, EinundzwanzigPleb $actor, string $reason): void
    {
        DB::transaction(function () use ($round, $member, $actor, $reason): void {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());

            $this->authorizeActor($actor, 'enrolByHand', $round);

            if (! in_array($round->stateAt(now()), [ElectionRoundState::Scheduled, ElectionRoundState::Open], true)) {
                throw new ElectionRoundStateException('A member can be enrolled by hand only while the round is opened and has not ended.');
            }

            $member = EinundzwanzigPleb::query()->findOrFail($member->getKey());

            if ($member->isErased() || ! in_array($member->association_status, [AssociationStatus::ACTIVE, AssociationStatus::HONORARY], true)) {
                throw new ElectionActionRefusedException('Only an active or honorary member can be enrolled.');
            }

            if (! $this->roll->enrolByHand($round, $member, now())) {
                throw new ElectionActionRefusedException('This member is on the roll of the round already.');
            }

            $this->audit->append($round->election, ElectionAuditAction::VoterEnrolledByHand, $actor, round: $round, subject: $member, details: ['reason' => $reason]);
        });
    }

    /**
     * Enter a member as entitled to vote in `$election` although the payment
     * flow knows no qualifying fee — paid in cash, say. The audit line without
     * a round IS the entitlement: `ElectoralRoll::entitled()` reads it, so the
     * candidacy can be approved and every round opened afterwards puts the
     * member on its roll. It cannot be taken back, like every audit line.
     *
     * Only while a round of the election is neither opened nor annulled —
     * otherwise it changes no roll; an open round takes `handle()`.
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException when no round is left to open
     * @throws ElectionActionRefusedException when the member is no active or honorary member, or entitled already
     */
    public function entitle(Election $election, EinundzwanzigPleb $member, EinundzwanzigPleb $actor, string $reason): void
    {
        DB::transaction(function () use ($election, $member, $actor, $reason): void {
            $election = Election::query()->lockForUpdate()->findOrFail($election->getKey());

            $this->authorizeActor($actor, 'update', $election);

            if (! $election->rounds()->whereNull('opened_at')->whereNull('annulled_at')->exists()) {
                throw new ElectionRoundStateException('A member can be entitled by hand only while a round of the election has not been opened.');
            }

            $member = EinundzwanzigPleb::query()->findOrFail($member->getKey());

            if ($member->isErased() || ! in_array($member->association_status, ElectoralRoll::VOTING_STATUSES, true)) {
                throw new ElectionActionRefusedException('Only an active or honorary member can be entitled.');
            }

            if ($this->roll->reasonFor($election, $member, now()) !== null) {
                throw new ElectionActionRefusedException('This member is entitled already.');
            }

            $this->audit->append($election, ElectionAuditAction::VoterEnrolledByHand, $actor, subject: $member, details: ['reason' => $reason]);
        });
    }
}
