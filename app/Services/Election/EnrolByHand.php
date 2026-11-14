<?php

namespace App\Services\Election;

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The Wahlleitung puts a member on the roll of a round by hand, with a
 * reason that goes into the audit log.
 *
 * Only while the round is opened and has not ended, and only for an active
 * or honorary member — a category without a vote does not get one by hand.
 * The fee is the Wahlleitung's judgement; that is what the reason is for.
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
}
