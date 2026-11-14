<?php

namespace App\Services\Election;

use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionBallotChoice;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Seal a round: close it for good and rewrite its ballot rows and receipts
 * in random order.
 *
 * One-way. There is no reopen and no extension, here or anywhere else.
 *
 * A live round is sealed by hand whenever the assembly is done voting. A
 * window round is sealed once its window has run out; ending a window EARLY
 * is a different act that needs a second person and is not offered here.
 *
 * A round whose window is over already refuses ballots by the clock alone.
 * What sealing adds is the rewrite and the manifest, and results may only be
 * read after it. Calling this on a sealed round changes nothing.
 */
class CloseRound
{
    public function __construct(protected RoundManifest $manifest) {}

    /**
     * @throws ElectionRoundStateException
     */
    public function handle(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            /*
             * The same row lock `CastBallot` takes. A cast that got in first
             * commits before this continues, and one that comes after sees
             * `closed_at` — so no ballot can land behind the rewrite.
             */
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $now = now();

            if ($round->closed_at !== null) {
                return $round;
            }

            $state = $round->stateAt($now);

            if (! in_array($state, [ElectionRoundState::Open, ElectionRoundState::Closed], true)) {
                throw new ElectionRoundStateException('Only a round that has been opened and not annulled can be sealed.');
            }

            if ($state === ElectionRoundState::Open && $round->mode === ElectionRoundMode::Window) {
                throw new ElectionRoundStateException('A window round cannot be sealed before its window has run out.');
            }

            $round->closed_at = $now;
            $round->save();

            $this->rewrite($round);

            /*
             * In the same transaction as the rewrite: a round is never
             * sealed without its manifest. The audit-log head stays null
             * until the audit log of package P2-B2 is merged; it is wired in
             * here (`ElectionAuditLog::headHash()`).
             */
            $this->manifest->record($round, auditHeadHash: null);

            return $round;
        });
    }

    /**
     * Delete every ballot row and receipt of the round and insert them again
     * in random order, the ballot rows under new random ids.
     *
     * Without this a cast leaves a trail the columns do not show: its mark,
     * its ballot rows and its receipt were written by one transaction, so on
     * PostgreSQL they share a transaction id and sit in the same relative
     * position in their tables. After the rewrite every ballot row and every
     * receipt of the round carries the transaction of the close and a
     * position drawn by the CSPRNG.
     */
    protected function rewrite(ElectionRound $round): void
    {
        $randomizer = new Randomizer;

        $choices = ElectionBallotChoice::query()
            ->where('election_round_id', $round->getKey())
            ->toBase()
            ->get(['election_candidate_id', 'choice'])
            ->map(fn (object $row): array => [
                'id' => ElectionBallotChoice::newId(),
                'election_round_id' => $round->getKey(),
                'election_candidate_id' => $row->election_candidate_id,
                'choice' => $row->choice,
            ])
            ->all();

        ElectionBallotChoice::query()->where('election_round_id', $round->getKey())->delete();

        foreach (array_chunk($randomizer->shuffleArray($choices), 500) as $chunk) {
            ElectionBallotChoice::query()->insert($chunk);
        }

        $receipts = ElectionReceipt::query()
            ->where('election_round_id', $round->getKey())
            ->toBase()
            ->pluck('hash')
            ->map(fn (string $hash): array => [
                'election_round_id' => $round->getKey(),
                'hash' => $hash,
            ])
            ->all();

        ElectionReceipt::query()->where('election_round_id', $round->getKey())->delete();

        foreach (array_chunk($randomizer->shuffleArray($receipts), 500) as $chunk) {
            ElectionReceipt::query()->insert($chunk);
        }
    }
}
