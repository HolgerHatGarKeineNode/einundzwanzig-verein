<?php

namespace App\Services\Election;

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionBallotRefusedException;
use App\Exceptions\ElectionBallotWriteException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Models\ElectionVote;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Nightwatch\Facades\Nightwatch;
use SensitiveParameterValue;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Take in one vote: a Nostr event the member signed (`VoteEvent`).
 *
 * One transaction, one row: the vote with its event, its signer and its
 * choices. Checked before it is written: the round is open, the member is on
 * its roll, the event is authentic, signed by the member, of the vote kind,
 * bound to this election and round, signed within the open period, and its
 * choices fit the round's counting rule. One vote per member and round; a
 * second one is refused, there is no changing a vote.
 *
 * The row is written through the query builder — no model events, no job,
 * no notification, no log line. The cast runs in a Sentry scope of its own,
 * without a current span and discarded afterwards, with Nightwatch paused
 * (E23). Attempts are throttled per member.
 */
class CastBallot
{
    /** Attempts per member within `DECAY_SECONDS`, refused or not. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function __construct(protected ElectoralRoll $roll) {}

    /**
     * @param  array<mixed>  $event  the signed vote event as the browser sent it, see `VoteEvent`
     *
     * `#[\SensitiveParameter]`: a stack trace — in a log, an error report or
     * a debug page — shows it as `SensitiveParameterValue`. The transaction
     * closure gets it wrapped the same way, because a closure carries the
     * variables it uses, and a trace holds the closure.
     *
     * A database error other than the refusals a voter is told about leaves
     * as an `ElectionBallotWriteException` that names only the SQLSTATE.
     *
     * @throws ElectionBallotRefusedException
     * @throws ElectionBallotWriteException
     */
    public function handle(
        ElectionRound $round,
        EinundzwanzigPleb $voter,
        #[\SensitiveParameter] array $event,
    ): void {
        $ballot = new SensitiveParameterValue($event);

        SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($round, $voter, $ballot): void {
            $scope->setSpan(null);

            Nightwatch::ignore(function () use ($round, $voter, $ballot): void {
                $this->throttle($voter);

                try {
                    $this->write($round, $voter, $ballot);
                } catch (\PDOException $exception) {
                    throw new ElectionBallotWriteException('The database refused to take in the vote (SQLSTATE '.$exception->getCode().').');
                }
            });
        });
    }

    /**
     * Inside the blackout too: the limiter's cache events carry a key that
     * names the member. The key holds a count and an expiry, never a round
     * or a choice.
     *
     * @throws ElectionBallotRefusedException
     */
    protected function throttle(EinundzwanzigPleb $voter): void
    {
        $key = 'election-cast:'.$voter->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ElectionBallotRefusedException(ElectionBallotRefusal::TooManyAttempts);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
    }

    /**
     * @param  SensitiveParameterValue  $ballot  holds the signed event
     *
     * @throws ElectionBallotRefusedException
     */
    protected function write(ElectionRound $round, EinundzwanzigPleb $voter, SensitiveParameterValue $ballot): void
    {
        DB::transaction(function () use ($round, $voter, $ballot): void {
            $event = $ballot->getValue();

            /*
             * Whether the round is open is decided HERE: on the row as it is
             * now, locked, against the clock of this transaction. Not on the
             * instance the caller loaded when the page was rendered.
             */
            $round = ElectionRound::query()->lockForUpdate()->find($round->getKey());
            $now = now();

            if ($round === null || $round->stateAt($now) !== ElectionRoundState::Open) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::RoundNotOpen);
            }

            if (! $this->roll->contains($round, $voter)) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::NotOnRoll);
            }

            $failures = VoteEvent::failures($event, $round, (string) $voter->pubkey, $now);

            if ($failures !== []) {
                throw new ElectionBallotRefusedException($failures[0]);
            }

            $selection = VoteEvent::selection($event)
                ?? throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidChoices);

            // Approved candidacies only: a pending, rejected or withdrawn one
            // is not on the ballot, whatever id the event names.
            $round->counting_rule->ballotRows(
                $selection,
                $round->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('id')->all(),
                $round->seats,
            );

            /*
             * No `exists()` beforehand: two requests racing past a check
             * would both write a vote. The unique index on round and member
             * is the lock against a double vote.
             */
            try {
                ElectionVote::query()->insert([
                    'election_round_id' => $round->getKey(),
                    'einundzwanzig_pleb_id' => $voter->getKey(),
                    'pubkey' => $event['pubkey'],
                    'event_id' => $event['id'],
                    'event' => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'choices' => json_encode((object) $selection, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::AlreadyVoted);
            }
        });
    }
}
