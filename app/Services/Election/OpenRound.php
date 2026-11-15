<?php

namespace App\Services\Election;

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(protected ElectoralRoll $roll, protected TallyRound $tally) {}

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

        if (! $round->candidates()->where('status', ElectionCandidateStatus::Approved)->exists()) {
            throw new ElectionRoundStateException('A round without approved candidates cannot be opened.');
        }

        if ($round->parent_round_id !== null) {
            $parent = $round->parent;

            if ($parent === null || $parent->election_event_id !== $round->election_event_id || ! $parent->isSealed()) {
                throw new ElectionRoundStateException('A run-off can only be opened once the round it belongs to is sealed.');
            }
        }

        $this->assertNobodyStandsAgainAfterBeingElected($round);
    }

    /**
     * Whoever is elected in a round of the election does not stand in the
     * rounds after it.
     *
     * "After" is the order of `position`; the round a run-off belongs to
     * counts as earlier whatever its position. For every earlier round that
     * shares a candidate with this one, the earlier result has to be known:
     *
     *  - not sealed yet (a draft, open, or merely over by the clock) → refused,
     *    nobody can say yet whether that candidate is elected there;
     *  - elected there → refused;
     *  - level on votes in a tie there → refused, unless this round is the
     *    run-off of exactly that round, which is what a run-off is for, or a
     *    run-off of that round is sealed and not annulled: the tie is decided
     *    there. That run-off is an earlier round itself — it opened only
     *    once every round before it that shares a candidate was sealed —
     *    so whoever it elects is caught by the second case, and a run-off
     *    that ends level again blocks by this one.
     *
     * Refusing instead of quietly dropping the candidate keeps the list of
     * candidates the election officer's to change. An annulled round has no
     * result and elects nobody.
     *
     * @throws ElectionRoundStateException
     */
    protected function assertNobodyStandsAgainAfterBeingElected(ElectionRound $round): void
    {
        $standing = $round->candidates()->pluck('einundzwanzig_pleb_id')->map(fn (mixed $id): int => (int) $id)->all();

        $earlier = ElectionRound::query()
            ->where('election_event_id', $round->election_event_id)
            ->whereKeyNot($round->getKey())
            ->whereNull('annulled_at')
            ->where(function (Builder $query) use ($round): void {
                $query->where('position', '<', $round->position)
                    ->orWhere('id', $round->parent_round_id);
            })
            ->with(['candidates', 'children'])
            ->get();

        foreach ($earlier as $other) {
            $shared = $other->candidates
                ->filter(fn (ElectionCandidate $candidate): bool => in_array((int) $candidate->einundzwanzig_pleb_id, $standing, true))
                ->map(fn (ElectionCandidate $candidate): int => (int) $candidate->getKey())
                ->values()
                ->all();

            if ($shared === []) {
                continue;
            }

            if (! $other->isSealed()) {
                throw new ElectionRoundStateException('A candidate of this round also stands in an earlier round of the election that is not sealed yet.');
            }

            $result = $this->tally->handle($other);

            if (array_intersect($shared, $result->elected) !== []) {
                throw new ElectionRoundStateException('A candidate of this round has been elected in an earlier round of the election.');
            }

            $decidedByRunOff = $other->children->contains(fn (ElectionRound $runOff): bool => $runOff->isSealed());

            if ((int) $other->getKey() !== (int) $round->parent_round_id && ! $decidedByRunOff && array_intersect($shared, $result->tied) !== []) {
                throw new ElectionRoundStateException('A candidate of this round is level in an undecided tie of an earlier round of the election.');
            }
        }
    }
}
