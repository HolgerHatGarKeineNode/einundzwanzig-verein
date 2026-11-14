<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionMark;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Stimmt auf Papier": a member votes on a paper ballot in the assembly.
 *
 * What is written here is the mark alone — that the member has voted in the
 * round — and NO ballot row: the paper ballot is counted on paper. The mark
 * is what blocks an online ballot afterwards (`CastBallot` inserts its mark
 * first and is refused on the unique index), and an online ballot cast before
 * blocks the paper vote the same way.
 *
 * Two people sign: the Wahlleitung records, a second person who does not
 * stand in the round confirms. Both are named in the audit line.
 */
class RecordPaperVote
{
    use AuthorizesElectionActor;

    public function __construct(protected ElectoralRoll $roll, protected ElectionAuditLog $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     * @throws ElectionActionRefusedException
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $voter, EinundzwanzigPleb $actor, EinundzwanzigPleb $second): void
    {
        DB::transaction(function () use ($round, $voter, $actor, $second): void {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());

            $this->authorizeActor($actor, 'recordPaperVote', $round);

            $people = [(int) $actor->getKey(), (int) $second->getKey(), (int) $voter->getKey()];

            if (count(array_unique($people)) !== 3) {
                throw new ElectionActionRefusedException('A paper vote needs a second person who is neither the Wahlleitung nor the voter.');
            }

            $this->authorizeActor($second, 'confirmPaperVote', $round);

            if ($round->stateAt(now()) !== ElectionRoundState::Open) {
                throw new ElectionRoundStateException('A paper vote is recorded only while the round takes ballots.');
            }

            if (! $this->roll->contains($round, $voter)) {
                throw new ElectionActionRefusedException('This member is not on the roll of the round.');
            }

            try {
                ElectionMark::query()->insert([
                    'election_round_id' => $round->getKey(),
                    'einundzwanzig_pleb_id' => $voter->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ElectionActionRefusedException('This member has voted in the round already.');
            }

            $this->audit->append($round->election, ElectionAuditAction::PaperVoteRecorded, $actor, round: $round, subject: $voter, second: $second);
        });
    }
}
