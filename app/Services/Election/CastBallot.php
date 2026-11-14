<?php

namespace App\Services\Election;

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionBallotChoice;
use App\Models\ElectionMark;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Take in one ballot.
 *
 * One transaction, three writes that share nothing: the mark ("this member
 * has voted"), the ballot rows (what was chosen) and the receipt hash (that
 * a ballot was taken in). All three or none.
 *
 * What this class must never do, because each would put voter and choice
 * side by side somewhere outside those tables: dispatch a job, fire an
 * event, send a notification, write a log line, return or throw anything
 * that names the member or the choice. The rows are written through the
 * query builder for that reason too — no model events, no model instances
 * holding a ballot.
 */
class CastBallot
{
    public function __construct(protected ElectoralRoll $roll) {}

    /**
     * @param  array<array-key, mixed>  $selection  candidate id => choice value, see `ElectionCountingRule::ballotRows()`; empty for a blank ballot
     * @param  string  $receiptCode  made up by the voter's browser; only its hash is stored
     *
     * @throws ElectionBallotRefusedException
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $voter, array $selection, string $receiptCode): void
    {
        DB::transaction(function () use ($round, $voter, $selection, $receiptCode): void {
            /*
             * Whether the round is open is decided HERE: on the row as it is
             * now, locked, against the clock of this transaction. Not on the
             * instance the caller loaded when the page was rendered.
             */
            $round = ElectionRound::query()->lockForUpdate()->find($round->getKey());

            if ($round === null || $round->stateAt(now()) !== ElectionRoundState::Open) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::RoundNotOpen);
            }

            if (! $this->roll->contains($round, $voter)) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::NotOnRoll);
            }

            // Approved candidacies only: a pending, rejected or withdrawn one
            // is not on the ballot, whatever id the request names.
            $rows = $round->counting_rule->ballotRows(
                $selection,
                $round->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('id')->all(),
                $round->seats,
            );

            if (preg_match(ElectionReceipt::CODE_PATTERN, $receiptCode) !== 1) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidReceipt);
            }

            /*
             * The mark goes first, and its unique index is the lock against
             * a double vote. No `exists()` beforehand: two requests racing
             * past a check would both write a ballot, and a surplus ballot
             * can never be found again.
             */
            try {
                ElectionMark::query()->insert([
                    'election_round_id' => $round->getKey(),
                    'einundzwanzig_pleb_id' => $voter->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::AlreadyVoted);
            }

            ElectionBallotChoice::query()->insert(array_map(fn (array $row): array => [
                'id' => ElectionBallotChoice::newId(),
                'election_round_id' => $round->getKey(),
                'election_candidate_id' => $row['candidate'],
                'choice' => $row['choice']->value,
            ], $rows));

            try {
                ElectionReceipt::query()->insert([
                    'election_round_id' => $round->getKey(),
                    'hash' => ElectionReceipt::hashFor($round->getKey(), $receiptCode),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidReceipt);
            }
        });
    }
}
