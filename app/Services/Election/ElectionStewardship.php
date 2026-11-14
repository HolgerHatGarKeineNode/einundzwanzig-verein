<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The privileged acts on an election and its rounds, each with the rights
 * check of `ElectionPolicy` and an audit line naming the board member who
 * acts, both inside the transaction that does the act. The check runs at the
 * moment of the act, so a member who left the board since the page loaded is
 * refused.
 *
 * `OpenRound` and `CloseRound` stay what they are — the mechanics, with no
 * notion of who acts. This class is the door a person goes through.
 */
class ElectionStewardship
{
    use AuthorizesElectionActor;

    public function __construct(
        protected OpenRound $openRound,
        protected CloseRound $closeRound,
        protected ElectionAuditLog $audit,
    ) {}

    /**
     * Announce the election to the members.
     *
     * @throws AuthorizationException
     */
    public function announce(Election $election, EinundzwanzigPleb $actor): Election
    {
        return DB::transaction(function () use ($election, $actor): Election {
            $election = Election::query()->lockForUpdate()->findOrFail($election->getKey());

            $this->authorizeActor($actor, 'announce', $election);

            $election->forceFill(['announced_at' => now()])->save();

            $this->audit->append($election, ElectionAuditAction::ElectionAnnounced, $actor);

            return $election;
        });
    }

    /**
     * Delete an election whose rounds are all drafts. Its audit lines stay,
     * the deletion line last.
     *
     * @throws AuthorizationException
     */
    public function delete(Election $election, EinundzwanzigPleb $actor): void
    {
        DB::transaction(function () use ($election, $actor): void {
            $election = Election::query()->lockForUpdate()->findOrFail($election->getKey());

            $this->authorizeActor($actor, 'delete', $election);

            $this->audit->append($election, ElectionAuditAction::ElectionDeleted, $actor);

            $election->delete();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     */
    public function open(ElectionRound $round, EinundzwanzigPleb $actor): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor): ElectionRound {
            $round = $this->lockedRound($round);

            $this->authorizeActor($actor, 'open', $round);

            $round = $this->openRound->handle($round);

            $this->audit->append($round->election, ElectionAuditAction::RoundOpened, $actor, round: $round);

            return $round;
        });
    }

    /**
     * Seal a round that no longer takes ballots. A round that still does —
     * a live round in the assembly, a window that has not run out — is ended
     * with `closeEarly()`.
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     */
    public function close(ElectionRound $round, EinundzwanzigPleb $actor): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor): ElectionRound {
            $round = $this->lockedRound($round);

            $this->authorizeActor($actor, 'close', $round);

            if (in_array($round->stateAt(now()), [ElectionRoundState::Scheduled, ElectionRoundState::Open], true)) {
                throw new ElectionRoundStateException('A round that still takes ballots is ended early.');
            }

            return $this->seal($round, $actor, ElectionAuditAction::RoundClosed, null);
        });
    }

    /**
     * End a round while it takes ballots. Sealing a WINDOW round before its
     * end is up to `CloseRound`, which refuses it as of this writing; a live
     * round is sealed.
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     */
    public function closeEarly(ElectionRound $round, EinundzwanzigPleb $actor, string $reason): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor, $reason): ElectionRound {
            $round = $this->lockedRound($round);

            $this->authorizeActor($actor, 'closeEarly', $round);

            if ($round->stateAt(now()) !== ElectionRoundState::Open) {
                throw new ElectionRoundStateException('Only a round that takes ballots can be ended early.');
            }

            return $this->seal($round, $actor, ElectionAuditAction::RoundClosedEarly, $reason);
        });
    }

    /**
     * Annul an opened round. One-way, like sealing; its ballots are never
     * handed out (`ElectionRound::sealedChoices()`).
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     */
    public function annul(ElectionRound $round, EinundzwanzigPleb $actor, string $reason): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor, $reason): ElectionRound {
            $round = $this->lockedRound($round);

            $this->authorizeActor($actor, 'annul', $round);

            if ($round->opened_at === null || $round->annulled_at !== null) {
                throw new ElectionRoundStateException('Only a round that has been opened and not annulled can be annulled.');
            }

            $round->annulled_at = now();
            $round->save();

            $this->audit->append($round->election, ElectionAuditAction::RoundAnnulled, $actor, round: $round, details: ['reason' => $reason]);

            return $round;
        });
    }

    protected function seal(ElectionRound $round, EinundzwanzigPleb $actor, ElectionAuditAction $action, ?string $reason): ElectionRound
    {
        if ($round->closed_at !== null) {
            throw new ElectionRoundStateException('This round is sealed already.');
        }

        $round = $this->closeRound->handle($round);

        $this->audit->append($round->election, $action, $actor, round: $round, details: $reason === null ? [] : ['reason' => $reason]);

        return $round;
    }

    protected function lockedRound(ElectionRound $round): ElectionRound
    {
        return ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
    }
}
