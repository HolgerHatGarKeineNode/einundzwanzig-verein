<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundMode;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Set up the run-off of a sealed round as a draft round of its own, which
 * `OpenRound` then opens like any other (it requires the parent sealed).
 *
 * Who stands in it:
 *
 *  - a tie: the candidates level on votes, for the seats they were level for;
 *  - a single-majority round where nobody passed half: the two best placed
 *    (and whoever is level with the second), for the open seat.
 *
 * Counted by `most_votes`: in a run-off whoever is named most often takes
 * the seat. A tie in the run-off stays a tie — the application never breaks
 * one itself.
 *
 * The run-off is placed right after its round when every later round is
 * still a draft, so it is voted on next; otherwise at the end.
 */
class CreateRunOff
{
    use AuthorizesElectionActor;

    public function __construct(protected TallyRound $tally, protected ElectionAuditLog $audit) {}

    /**
     * The candidate ids of `$round` a run-off would be between, and the seats
     * it would fill — empty when the result needs no run-off.
     *
     * @return array{candidates: list<int>, seats: int}
     *
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function proposal(ElectionRound $round): array
    {
        $result = $this->tally->handle($round);

        if ($result->outcome === ElectionOutcome::Tie) {
            return ['candidates' => $result->tied, 'seats' => $result->tiedSeats];
        }

        if ($result->outcome === ElectionOutcome::Understaffed
            && $result->rule === ElectionCountingRule::SingleMajority
            && count($result->counts) >= 2) {
            $scores = array_map(fn (array $count): int => $count['for'], $result->counts);
            arsort($scores);
            $second = array_values($scores)[1];

            if ($second > 0) {
                $best = array_keys(array_filter($scores, fn (int $score): bool => $score >= $second));
                sort($best);

                return ['candidates' => $best, 'seats' => $result->seats - count($result->elected)];
            }
        }

        return ['candidates' => [], 'seats' => 0];
    }

    /**
     * @throws AuthorizationException
     * @throws ElectionRoundStateException when the round is not sealed
     * @throws ElectionActionRefusedException when no run-off is needed or one exists already
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $actor): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor): ElectionRound {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $election = $round->election;

            $this->authorizeActor($actor, 'update', $election);

            if ($round->annulled_at !== null) {
                throw new ElectionActionRefusedException('An annulled round needs no run-off.');
            }

            if ($round->children()->whereNull('annulled_at')->exists()) {
                throw new ElectionActionRefusedException('This round has a run-off already.');
            }

            ['candidates' => $candidateIds, 'seats' => $seats] = $this->proposal($round);

            if ($candidateIds === [] || $seats < 1) {
                throw new ElectionActionRefusedException('The result of this round needs no run-off.');
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

            $runOff = ElectionRound::query()->create([
                'election_event_id' => $election->getKey(),
                'parent_round_id' => $round->getKey(),
                'position' => $position,
                'title' => __('Stichwahl :title', ['title' => $round->title]),
                'mode' => ElectionRoundMode::Live,
                'counting_rule' => ElectionCountingRule::MostVotes,
                'seats' => $seats,
            ]);

            ElectionCandidate::query()
                ->whereKey($candidateIds)
                ->get()
                ->each(fn (ElectionCandidate $candidate) => ElectionCandidate::query()->create([
                    'election_round_id' => $runOff->getKey(),
                    'einundzwanzig_pleb_id' => $candidate->einundzwanzig_pleb_id,
                    'status' => ElectionCandidateStatus::Approved,
                    'statement' => $candidate->statement,
                ]));

            $this->audit->append($election, ElectionAuditAction::RunOffCreated, $actor, round: $runOff);

            return $runOff;
        });
    }
}
