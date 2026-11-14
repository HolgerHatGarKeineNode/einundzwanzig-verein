<?php

namespace App\Services\Election;

use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionRound;
use Illuminate\Support\Facades\DB;

/**
 * Open a draft round: freeze what is voted on and when, and write the
 * electoral roll.
 *
 * "Open" here means "no longer a draft". A window round may be opened ahead
 * of its `opens_at`; from then on the clock alone decides when ballots are
 * taken in and when they stop being taken in — nothing has to run at either
 * moment.
 */
class OpenRound
{
    /**
     * How long a live round stays open if nobody closes it by hand.
     */
    public const LIVE_MAX_MINUTES = 60;

    public function __construct(protected ElectoralRoll $roll) {}

    /**
     * @throws ElectionRoundStateException
     */
    public function handle(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $now = now();

            if ($round->stateAt($now) !== ElectionRoundState::Draft) {
                throw new ElectionRoundStateException('Only a draft round can be opened.');
            }

            $this->assertOpenable($round);

            if ($round->mode === ElectionRoundMode::Live) {
                $round->opens_at = $now;
                $round->closes_at = $now->copy()->addMinutes(self::LIVE_MAX_MINUTES);
            }

            if ($round->opens_at === null || $round->closes_at === null) {
                throw new ElectionRoundStateException('A window round needs a beginning and an end before it can be opened.');
            }

            if ($round->closes_at->lte($round->opens_at) || $round->closes_at->lte($now)) {
                throw new ElectionRoundStateException('The end of a round must lie after its beginning and in the future.');
            }

            if ($round->reveal_at !== null && $round->reveal_at->lt($round->closes_at)) {
                throw new ElectionRoundStateException('The result of a round cannot be revealed before the round ends.');
            }

            $round->opened_at = $now;
            $round->save();

            $this->roll->snapshot($round, $now);

            return $round;
        });
    }

    /**
     * @throws ElectionRoundStateException
     */
    protected function assertOpenable(ElectionRound $round): void
    {
        $election = $round->election;

        if ($election->fee_rule->needsFeeYear() && $election->fee_year === null) {
            throw new ElectionRoundStateException('The election names a fee rule that needs a fee year, and has none.');
        }

        if ($round->seats < 1) {
            throw new ElectionRoundStateException('A round needs at least one seat.');
        }

        if (! $round->candidates()->exists()) {
            throw new ElectionRoundStateException('A round without candidates cannot be opened.');
        }

        if ($round->parent_round_id === null) {
            return;
        }

        $parent = $round->parent;

        if ($parent === null || $parent->election_event_id !== $round->election_event_id || ! $parent->isSealed()) {
            throw new ElectionRoundStateException('A run-off can only be opened once the round it belongs to is sealed.');
        }
    }
}
