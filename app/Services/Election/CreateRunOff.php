<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionTieKind;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Set up the run-off of a sealed round as a draft round of its own, which
 * `OpenRound` then opens like any other (it requires the parent sealed).
 *
 * Who stands in it:
 *
 *  - a tie: the candidates level on votes, for the seats they were level for —
 *    only when the assembly resolved a run-off. The statutory answer to a tie
 *    is the chair's casting vote (Art. 6, `RecordCastingVote`); the two
 *    exclude each other, so there is no run-off after a casting vote;
 *  - a single-majority round where nobody passed half: the two best placed
 *    (and whoever is level with the second), for the open seat. That is no
 *    Stimmengleichheit and needs no resolution.
 *
 * Counted by `most_votes`: in a run-off whoever is named most often takes
 * the seat. A tie of yes = no is voted on again by `yes_no_per_candidate`,
 * since `most_votes` would elect a lone candidate with a single vote. A tie
 * in the run-off is again decided by casting vote, which ends the chain.
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
     * it would fill — empty when the effective result needs no run-off, a
     * tie decided by casting vote included. `needs_resolution` is true for a
     * tie: only the assembly can resolve that run-off.
     *
     * A round whose stored votes do not fit it has no count, so nothing is
     * proposed from it; `handle()` refuses its run-off outright.
     *
     * @return array{candidates: list<int>, seats: int, needs_resolution: bool}
     *
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function proposal(ElectionRound $round): array
    {
        try {
            $result = $this->tally->effective($round);
        } catch (UnexpectedValueException) {
            return ['candidates' => [], 'seats' => 0, 'needs_resolution' => false];
        }

        if ($result->outcome === ElectionOutcome::Tie) {
            return ['candidates' => $result->tied, 'seats' => $result->tiedSeats, 'needs_resolution' => true];
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

                return ['candidates' => $best, 'seats' => $result->seats - count($result->elected), 'needs_resolution' => false];
            }
        }

        return ['candidates' => [], 'seats' => 0, 'needs_resolution' => false];
    }

    /**
     * @param  bool  $resolvedByAssembly  the election officer confirms that the assembly resolved a run-off; required for a tie
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException when the round is not sealed
     * @throws ElectionActionRefusedException when no run-off is needed, one exists already, the tie was decided by casting vote, the assembly's resolution is missing or the votes cannot be counted
     */
    public function handle(ElectionRound $round, EinundzwanzigPleb $actor, bool $resolvedByAssembly = false): ElectionRound
    {
        return DB::transaction(function () use ($round, $actor, $resolvedByAssembly): ElectionRound {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $election = $round->election;

            $this->authorizeActor($actor, 'update', $election);

            if ($round->annulled_at !== null) {
                throw new ElectionActionRefusedException('An annulled round needs no run-off.');
            }

            if ($round->children()->whereNull('annulled_at')->exists()) {
                throw new ElectionActionRefusedException('This round has a run-off already.');
            }

            if (ElectionCastingVote::query()->where('election_round_id', $round->getKey())->exists()) {
                throw new ElectionActionRefusedException('The tie of this round has been decided by casting vote.');
            }

            try {
                $this->tally->handle($round);
            } catch (UnexpectedValueException) {
                throw new ElectionActionRefusedException('The stored votes of this round cannot be counted; check it with election:verify.');
            }

            ['candidates' => $candidateIds, 'seats' => $seats, 'needs_resolution' => $needsResolution] = $this->proposal($round);

            if ($candidateIds === [] || $seats < 1) {
                throw new ElectionActionRefusedException('The result of this round needs no run-off.');
            }

            if ($needsResolution && ! $resolvedByAssembly) {
                throw new ElectionActionRefusedException('A run-off for a tie needs the resolution of the assembly; by default the chair casts the deciding vote.');
            }

            $yesEqualsNo = $this->tally->handle($round)->tieKind === ElectionTieKind::YesEqualsNo;

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
                'counting_rule' => $yesEqualsNo ? ElectionCountingRule::YesNoPerCandidate : ElectionCountingRule::MostVotes,
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

            $this->audit->append($election, ElectionAuditAction::RunOffCreated, $actor, round: $runOff, details: $needsResolution ? ['resolution' => 'assembly'] : []);

            return $runOff;
        });
    }
}
