<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
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
     * Annul an opened round. One-way, like sealing; its votes are never
     * counted (`TallyRound`).
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

            // A run-off decides the round it belongs to; without that round it has
            // nothing to decide. Every run-off below goes with it, whatever its state.
            $this->annulRunOffsOf($round, $actor);

            return $round;
        });
    }

    /**
     * Strike a first-level round that was never opened: the way out for a
     * round nobody can stand in any more (everyone elected elsewhere,
     * withdrawn or not approvable), which could otherwise never be opened
     * and never be finished. It is annulled with the board's reason — void
     * like any annulled round, never opened, and never awaiting a repeat.
     *
     * A run-off is not struck: annulled, it would only be proposed again
     * (`CreateRunOff` counts annulled run-offs as none).
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     */
    public function strike(ElectionRound $round, EinundzwanzigPleb $actor, string $reason): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor, $reason): ElectionRound {
            $round = $this->lockedRound($round);

            $this->authorizeActor($actor, 'annul', $round);

            if ($round->opened_at !== null || $round->annulled_at !== null || $round->parent_round_id !== null) {
                throw new ElectionRoundStateException('Only a first-level round that has never been opened and is not annulled can be struck.');
            }

            $round->annulled_at = now();
            $round->save();

            $this->audit->append($round->election, ElectionAuditAction::RoundAnnulled, $actor, round: $round, details: ['reason' => $reason]);

            return $round;
        });
    }

    /**
     * Annul every run-off below `$round` that is not annulled yet, deepest last,
     * each with its own audit line naming the round that took it along.
     */
    protected function annulRunOffsOf(ElectionRound $round, EinundzwanzigPleb $actor): void
    {
        $runOffs = $round->children()->whereNull('annulled_at')->lockForUpdate()->get();

        foreach ($runOffs as $runOff) {
            // The same moment as the round that took it along, so no screen mistakes
            // a run-off for the round the board annulled.
            $runOff->annulled_at = $round->annulled_at ?? now();
            $runOff->save();

            $this->audit->append($round->election, ElectionAuditAction::RoundAnnulled, $actor, round: $runOff, details: [
                'reason' => __('Mit „:title“ annulliert', ['title' => $round->title]),
            ]);

            $this->annulRunOffsOf($runOff, $actor);
        }
    }

    /**
     * Set up an annulled round again as a new draft: same title with
     * "(Wiederholung)", same seats, counting rule and mode, and the
     * candidates that were approved in it. It is opened like any other round.
     *
     * Placed right after the annulled round when every later round is still
     * a draft, so it is voted on next; otherwise at the end — positions of
     * opened rounds are frozen. The audit line names the annulled round,
     * which is how `awaitsRepeat()` knows it has been repeated.
     *
     * @throws AuthorizationException
     * @throws ElectionActionRefusedException when the round does not await a repeat
     */
    public function repeat(ElectionRound $round, EinundzwanzigPleb $actor): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor): ElectionRound {
            $round = $this->lockedRound($round);
            $election = $round->election;

            $this->authorizeActor($actor, 'update', $election);

            if (! $this->awaitsRepeat($round)) {
                throw new ElectionActionRefusedException('Only an annulled round that has not been repeated can be repeated.');
            }

            $later = ElectionRound::query()
                ->where('election_event_id', $election->getKey())
                ->where('position', '>', $round->position)
                ->orderByDesc('position')
                ->get();

            if ($later->every(fn (ElectionRound $other): bool => $other->opened_at === null)) {
                // Highest first, so no two rounds ever share a position.
                $later->each(fn (ElectionRound $other) => $other->forceFill(['position' => $other->position + 1])->save());
                $position = $round->position + 1;
            } else {
                $position = (int) $later->max('position') + 1;
            }

            $repeat = ElectionRound::query()->create([
                'election_event_id' => $election->getKey(),
                'position' => $position,
                'title' => self::repeatTitle($round->title),
                'mode' => $round->mode,
                'counting_rule' => $round->counting_rule,
                'seats' => $round->seats,
            ]);

            $round->candidates()
                ->where('status', ElectionCandidateStatus::Approved)
                ->orderBy('id')
                ->get()
                ->each(fn (ElectionCandidate $candidate) => ElectionCandidate::query()->create([
                    'election_round_id' => $repeat->getKey(),
                    'einundzwanzig_pleb_id' => $candidate->einundzwanzig_pleb_id,
                    'status' => ElectionCandidateStatus::Approved,
                    'statement' => $candidate->statement,
                ]));

            $this->audit->append($election, ElectionAuditAction::RoundRepeated, $actor, round: $round);

            return $repeat;
        });
    }

    /**
     * Is `$round` annulled and not yet set up again? Only a round of its
     * own: an annulled run-off is set up anew by `CreateRunOff`, which
     * counts annulled run-offs as none. A round struck before it was ever
     * opened (`strike()`) awaits nothing.
     */
    public function awaitsRepeat(ElectionRound $round): bool
    {
        if ($round->annulled_at === null || $round->opened_at === null || $round->parent_round_id !== null) {
            return false;
        }

        return ! ElectionAuditEntry::query()
            ->where('election_round_id', $round->getKey())
            ->where('action', ElectionAuditAction::RoundRepeated->value)
            ->exists();
    }

    /**
     * The title of the repeat of a round titled `$title` — one suffix, also
     * when a repeat is repeated.
     */
    public static function repeatTitle(string $title): string
    {
        $suffix = ' ('.__('Wiederholung').')';

        return str_ends_with($title, $suffix) ? $title : $title.$suffix;
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
