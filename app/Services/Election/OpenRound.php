<?php

namespace App\Services\Election;

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

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

    public function __construct(protected ElectoralRoll $roll, protected TallyRound $tally, protected CreateRunOff $runOffs) {}

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
            [$round->opens_at, $round->closes_at] = $this->schedule($round, $now);

            $round->opened_at = $now;
            $round->save();

            $this->roll->snapshot($round, $now);

            return $round;
        });
    }

    /**
     * Why `$round` cannot be opened right now, or null if it can — the same
     * checks `handle()` makes, asked without opening anything, so a screen
     * never offers a step this class would refuse.
     */
    public function refusal(ElectionRound $round): ?ElectionRoundStateException
    {
        $now = now();

        if ($round->stateAt($now) !== ElectionRoundState::Draft) {
            return new ElectionRoundStateException('Only a draft round can be opened.');
        }

        try {
            $this->assertOpenable($round);
            $this->schedule($round, $now);
        } catch (ElectionRoundStateException $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * When the round will take ballots once it is opened at `$now`: a live
     * round from now for LIVE_MAX_MINUTES, a window round its own times.
     * Nothing is written.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface} opens_at, closes_at
     *
     * @throws ElectionRoundStateException
     */
    protected function schedule(ElectionRound $round, CarbonInterface $now): array
    {
        [$opensAt, $closesAt] = $round->mode === ElectionRoundMode::Live
            ? [$now, $now->copy()->addMinutes(self::LIVE_MAX_MINUTES)]
            : [$round->opens_at, $round->closes_at];

        if ($opensAt === null || $closesAt === null) {
            throw new ElectionRoundStateException('A window round needs a beginning and an end before it can be opened.');
        }

        if ($closesAt->lte($opensAt) || $closesAt->lte($now)) {
            throw new ElectionRoundStateException('The end of a round must lie after its beginning and in the future.');
        }

        if ($round->reveal_at !== null && $round->reveal_at->lt($closesAt)) {
            throw new ElectionRoundStateException('The result of a round cannot be revealed before the round ends.');
        }

        return [$opensAt, $closesAt];
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
     * Whoever is elected in a round of the election, or is still level in an
     * undecided tie there, does not stand in a later round.
     *
     * The unit is the run-off chain, the contest: a first-level round together
     * with every run-off below it, at any depth. A contest takes the place of
     * its first-level round — its order is that round's `position`, wherever
     * its run-offs landed (`CreateRunOff` puts one right after its round or at
     * the end) — and its result is the one of its deepest sealed round, since
     * a run-off decides the round it belongs to. Annulled rounds have no
     * result and count for nothing.
     *
     * Opening a round checks every other contest that comes before its own
     * and shares a candidate with it:
     *
     *  - elected in any sealed round of that contest → refused;
     *  - level in the tie of the contest's decisive result → refused, the tie
     *    is not decided yet. Results are effective results
     *    (`TallyRound::effective()`): once the chair's casting vote is
     *    recorded the tie is decided, whom it elected counts as elected and
     *    the others are free;
     *  - among the best placed of the contest's decisive result when that is
     *    a single-majority round without a majority whose run-off has not
     *    been created yet (`CreateRunOff::proposal()`) → refused, the seat
     *    is still to be decided between them. Once the run-off exists it is
     *    an unsealed round of the contest and the next rule takes over;
     *  - standing in a round of that contest that is not sealed (a draft,
     *    open, or merely over by the clock) → refused, nobody can say yet
     *    whether that candidate is elected there.
     *
     * A run-off is checked as its contest is: by the order of its first-level
     * round. Its own chain is the reason it exists, so the tie it is there to
     * resolve does not block it; whoever is elected in one of its own earlier
     * rounds still does.
     *
     * Further rules: a round under an annulled round has no result and never
     * counts. Nobody is elected twice, whatever the order of the contests:
     * a candidate elected in a sealed round of any other contest is refused,
     * and so is one standing in an opened round of any other contest that is
     * not sealed yet (open, scheduled, or over by the clock) — a later
     * contest's draft does not count, so the earlier office keeps its first
     * claim (Wahlreglement § 4 Nr. 1, § 3 Nr. 7). The way on is to seal that
     * round or withdraw the candidacy in the round to open: the candidates of
     * an opened round are frozen. An unsealed round only counts candidacies
     * that are not withdrawn or rejected, so withdrawing a candidacy frees
     * the round it blocked.
     *
     * Refusing instead of quietly dropping the candidate keeps the list of
     * candidates the election officer's to change.
     *
     * @throws ElectionRoundStateException
     */
    protected function assertNobodyStandsAgainAfterBeingElected(ElectionRound $round): void
    {
        $standing = $round->candidates()
            ->whereNotIn('status', [ElectionCandidateStatus::Withdrawn, ElectionCandidateStatus::Rejected])
            ->pluck('einundzwanzig_pleb_id')->map(fn (mixed $id): int => (int) $id)->all();

        $rounds = ElectionRound::query()
            ->where('election_event_id', $round->election_event_id)
            ->with('candidates')
            ->get()
            ->keyBy(fn (ElectionRound $other): int => (int) $other->getKey());

        $chain = $this->chainOf($round, $rounds);
        $root = $chain[0];

        foreach (array_slice($chain, 0, -1) as $ancestor) {
            if ($ancestor->isSealed() && ($shared = $this->sharedCandidates($ancestor, $standing)) !== [] && ($elected = array_intersect($shared, $this->resultFor($ancestor, $shared)->elected)) !== []) {
                throw $this->blocked('A candidate of this round has been elected in an earlier round of the election.', $ancestor, $elected);
            }
        }

        // A round under an annulled round has no result: it never counts.
        $living = $rounds->filter(fn (ElectionRound $other): bool => collect($this->chainOf($other, $rounds))
            ->every(fn (ElectionRound $link): bool => $link->annulled_at === null));

        $otherContests = $living
            ->groupBy(fn (ElectionRound $other): int => (int) $this->chainOf($other, $rounds)[0]->getKey())
            ->reject(fn (Collection $contest, int $rootId): bool => $rootId === (int) $root->getKey());

        // Nobody is elected twice, whatever the order of the contests.
        foreach ($otherContests->flatten(1)->filter(fn (ElectionRound $other): bool => $other->isSealed()) as $other) {
            if (($shared = $this->sharedCandidates($other, $standing)) !== [] && ($elected = array_intersect($shared, $this->resultFor($other, $shared)->elected)) !== []) {
                throw $this->blocked('A candidate of this round has been elected in an earlier round of the election.', $other, $elected);
            }
        }

        $contests = $otherContests
            ->filter(fn (Collection $contest, int $rootId): bool => $rounds[$rootId]->position < $root->position)
            ->sortKeysUsing(fn (int $left, int $right): int => $rounds[$left]->position <=> $rounds[$right]->position);

        foreach ($contests as $contest) {
            $this->assertContestDecidedFor($contest, $standing, $rounds);
        }

        // Nobody is elected twice: an opened round that is not sealed holds
        // back every other contest it shares a candidate with, also
        // one placed before it. An earlier contest has refused above already.
        $undecided = $otherContests->flatten(1)->first(fn (ElectionRound $other): bool => $other->opened_at !== null
            && ! $other->isSealed()
            && $this->sharedCandidates($other, $standing, standingOnly: true) !== []);

        if ($undecided !== null) {
            throw $this->blocked(sprintf('A candidate of this round also stands in the opened round "%s" of the election, which is not sealed, so nobody knows yet whether they are elected there; seal that round first or withdraw the candidacy here.', $undecided->title), $undecided, $this->sharedCandidates($undecided, $standing, standingOnly: true));
        }
    }

    /**
     * @param  Collection<int, ElectionRound>  $contest  the rounds of one chain that are not annulled
     * @param  list<int>  $standing  the member ids standing in the round to open
     * @param  Collection<int, ElectionRound>  $rounds  every round of the election, by id
     *
     * @throws ElectionRoundStateException
     */
    protected function assertContestDecidedFor(Collection $contest, array $standing, Collection $rounds): void
    {
        $sharing = $contest->filter(fn (ElectionRound $other): bool => $this->sharedCandidates($other, $standing) !== []);

        if ($sharing->isEmpty()) {
            return;
        }

        foreach ($sharing->filter(fn (ElectionRound $other): bool => $other->isSealed()) as $other) {
            if (($elected = array_intersect($shared = $this->sharedCandidates($other, $standing), $this->resultFor($other, $shared)->elected)) !== []) {
                throw $this->blocked('A candidate of this round has been elected in an earlier round of the election.', $other, $elected);
            }
        }

        $decisive = $contest
            ->filter(fn (ElectionRound $other): bool => $other->isSealed())
            ->sortByDesc(fn (ElectionRound $other): int => count($this->chainOf($other, $rounds)))
            ->first();

        if ($decisive !== null && ($shared = $this->sharedCandidates($decisive, $standing)) !== [] && ($tied = array_intersect($shared, $this->resultFor($decisive, $shared)->tied)) !== []) {
            throw $this->blocked('A candidate of this round is level in an undecided tie of an earlier round of the election.', $decisive, $tied);
        }

        // A seat without a majority is decided by a run-off between the best
        // placed; until that run-off exists, they are as undecided as a tie.
        if ($decisive !== null
            && ! $contest->contains(fn (ElectionRound $other): bool => (int) $other->parent_round_id === (int) $decisive->getKey())
            && ($owed = array_intersect($this->sharedCandidates($decisive, $standing), $this->runOffs->proposal($decisive)['candidates'])) !== []) {
            throw $this->blocked('A candidate of this round is among the best placed of an earlier round of the election without a majority, whose second ballot is owed; create that second ballot first.', $decisive, $owed);
        }

        // An unsealed round only counts the candidacies still standing there.
        $unsealed = $sharing->first(fn (ElectionRound $other): bool => ! $other->isSealed() && $this->sharedCandidates($other, $standing, standingOnly: true) !== []);

        if ($unsealed !== null) {
            throw $this->blocked('A candidate of this round also stands in an earlier round of the election that is not sealed yet.', $unsealed, $this->sharedCandidates($unsealed, $standing, standingOnly: true));
        }
    }

    /**
     * The effective result of `$other`, a sealed round that shares the
     * candidates `$shared` with the round to open.
     *
     * A round whose stored votes do not fit it cannot say whether these
     * candidates are elected there, so the round to open is refused, naming
     * the first of them — never opened on a guess. A sealed round that shares
     * nobody is not counted here at all, so it holds back no other round.
     *
     * @param  array<int, int>  $shared
     *
     * @throws ElectionCandidateBlockedException
     */
    protected function resultFor(ElectionRound $other, array $shared): TallyResult
    {
        try {
            return $this->tally->effective($other);
        } catch (UnexpectedValueException) {
            throw $this->blocked('A candidate of this round stands in an earlier round of the election whose stored votes cannot be counted; check it with election:verify.', $other, $shared);
        }
    }

    /**
     * The refusal for the first member behind `$candidateIds`, candidacies of `$other`.
     *
     * @param  array<int, int>  $candidateIds
     */
    protected function blocked(string $message, ElectionRound $other, array $candidateIds): ElectionCandidateBlockedException
    {
        $candidate = $other->candidates
            ->filter(fn (ElectionCandidate $candidate): bool => in_array((int) $candidate->getKey(), $candidateIds, true))
            ->sortBy('id')
            ->first();

        return new ElectionCandidateBlockedException($message, (int) $candidate?->einundzwanzig_pleb_id, $other->title);
    }

    /**
     * `$round` and the rounds above it, the first-level round first.
     *
     * @param  Collection<int, ElectionRound>  $rounds  every round of the election, by id
     * @return non-empty-list<ElectionRound>
     */
    protected function chainOf(ElectionRound $round, Collection $rounds): array
    {
        $chain = [$round];

        while ($chain[0]->parent_round_id !== null && $rounds->has((int) $chain[0]->parent_round_id) && count($chain) <= $rounds->count()) {
            array_unshift($chain, $rounds[(int) $chain[0]->parent_round_id]);
        }

        return $chain;
    }

    /**
     * The candidate ids of `$round` that belong to members standing in the round to open.
     *
     * @param  list<int>  $standing
     * @param  bool  $standingOnly  leave out withdrawn and rejected candidacies
     * @return list<int>
     */
    protected function sharedCandidates(ElectionRound $round, array $standing, bool $standingOnly = false): array
    {
        return $round->candidates
            ->filter(fn (ElectionCandidate $candidate): bool => in_array((int) $candidate->einundzwanzig_pleb_id, $standing, true))
            ->reject(fn (ElectionCandidate $candidate): bool => $standingOnly && in_array($candidate->status, [ElectionCandidateStatus::Withdrawn, ElectionCandidateStatus::Rejected], true))
            ->map(fn (ElectionCandidate $candidate): int => (int) $candidate->getKey())
            ->values()
            ->all();
    }
}
