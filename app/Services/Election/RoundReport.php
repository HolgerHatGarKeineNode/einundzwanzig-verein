<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the board's live control, the share view and the minutes show about
 * a round: its state, how many have voted out of how many may, and after the
 * seal the result with candidate names and the manifest.
 *
 * Counts only. The votes of a round are reached as a count and through
 * `TallyRound`, which refuses until the round is sealed; nothing here can say
 * who voted, let alone how.
 *
 * The result is the effective one (`TallyRound::effective()`): with the
 * chair's casting vote applied once it is recorded. `casting_vote` then says
 * who decided what; the counts are the raw ones either way.
 *
 * A round annulled after its seal has no result, but its count stays on
 * record as `void_result` (jury run 1, F3): the minutes show it, marked as
 * not valid. Nothing else reads it.
 *
 * @phpstan-type ResultRow array{candidate_id: int, name: string, for: int, yes: int, no: int, elected: bool, tied: bool, by_casting_vote: bool}
 * @phpstan-type CastingVote array{chair: string, note: string|null, elected: list<string>, tied: list<string>, tied_seats: int, recorded_at: CarbonInterface}
 * @phpstan-type Result array{outcome: ElectionOutcome, blank: int, tied_seats: int, tie_kind: ElectionTieKind|null, casting_vote: CastingVote|null, rows: list<ResultRow>}
 * @phpstan-type Manifest array{hash: string, intact: bool}
 * @phpstan-type Report array{round: ElectionRound, state: ElectionRoundState, sealed: bool, over_by_clock: bool, roll: int, cast: int, candidates: list<string>, result: Result|null, manifest: Manifest|null, void_result: array{result: Result, manifest: Manifest|null}|null, reason: string|null, run_off: array{candidates: list<string>, seats: int, needs_resolution: bool}|null, has_run_off: bool, run_off_resolved: bool}
 */
class RoundReport
{
    public function __construct(
        protected TallyRound $tally,
        protected RoundManifest $manifests,
        protected CreateRunOff $runOffs,
    ) {}

    /**
     * The rounds of `$election` in ballot order — a run-off right behind the
     * round it belongs to when its position says so.
     *
     * @return Collection<int, Report>
     */
    public function forElection(Election $election, ?CarbonInterface $now = null): Collection
    {
        return ElectionRound::query()
            ->where('election_event_id', $election->getKey())
            ->orderBy('position')
            ->with(['candidates.pleb.profile', 'children'])
            ->withCount(['rollEntries', 'votes'])
            ->get()
            ->map(fn (ElectionRound $round): array => $this->of($round, $now));
    }

    /**
     * @return Report
     */
    public function of(ElectionRound $round, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $round->loadMissing(['candidates.pleb.profile', 'children']);
        $state = $round->stateAt($now);
        $sealed = $round->isSealed();

        $names = $round->candidates
            ->mapWithKeys(fn (ElectionCandidate $candidate): array => [(int) $candidate->getKey() => MemberProposal::authorNameOf($candidate->pleb)]);

        $result = null;
        $manifest = null;
        $runOff = null;
        $voidResult = null;
        $tiedRaw = false;

        if ($sealed) {
            $effective = $this->tally->effective($round);
            $tiedRaw = $effective->outcome === ElectionOutcome::Tie || $effective->castingVote !== null;
            $result = $this->resultOf($effective, $names);
            $manifest = $this->manifestOf($round);

            $proposal = $this->runOffs->proposal($round);

            if ($proposal['candidates'] !== []) {
                $runOff = [
                    'candidates' => array_map(fn (int $id): string => $names->get($id, ''), $proposal['candidates']),
                    'seats' => $proposal['seats'],
                    'needs_resolution' => $proposal['needs_resolution'],
                ];
            }
        } elseif ($round->closed_at !== null && $round->annulled_at !== null) {
            try {
                $voidResult = [
                    'result' => $this->resultOf($this->tally->count($round, $round->votes()->pluck('choices')->all()), $names),
                    'manifest' => $this->manifestOf($round, annulled: true),
                ];
            } catch (\UnexpectedValueException) {
                $voidResult = null;
            }
        }

        $hasRunOff = $round->children->whereNull('annulled_at')->isNotEmpty();

        return [
            'round' => $round,
            'state' => $state,
            'sealed' => $sealed,
            'over_by_clock' => $state === ElectionRoundState::Closed && ! $sealed,
            'roll' => (int) ($round->roll_entries_count ?? $round->rollEntries()->count()),
            'cast' => (int) ($round->votes_count ?? $round->votes()->count()),
            'candidates' => $round->candidates
                ->where('status', ElectionCandidateStatus::Approved)
                ->map(fn (ElectionCandidate $candidate): string => $names->get((int) $candidate->getKey(), ''))
                ->sort()
                ->values()
                ->all(),
            'result' => $result,
            'manifest' => $manifest,
            'void_result' => $voidResult,
            'reason' => $this->reasonOf($round),
            'run_off' => $runOff,
            'has_run_off' => $hasRunOff,
            // A run-off of a tie is there because the assembly resolved one.
            'run_off_resolved' => $hasRunOff && $tiedRaw,
        ];
    }

    /**
     * @param  Collection<int, string>  $names  candidate id => name
     * @return Result
     */
    protected function resultOf(TallyResult $tally, Collection $names): array
    {
        $decision = $tally->castingVote;
        $rows = collect($tally->counts)
            ->map(fn (array $count, int $id): array => [
                'candidate_id' => $id,
                'name' => $names->get($id, ''),
                'for' => $count['for'],
                'yes' => $count['yes'],
                'no' => $count['no'],
                'elected' => in_array($id, $tally->elected, true),
                'tied' => in_array($id, $tally->tied, true),
                'by_casting_vote' => $decision !== null && in_array($id, $decision->candidates, true),
            ])
            ->sortByDesc(fn (array $row): int => ($row['elected'] ? 1_000_000 : 0) + max($row['for'], $row['yes']) * 1000 - $row['no'])
            ->values()
            ->all();

        return [
            'outcome' => $tally->outcome,
            'blank' => $tally->blank,
            'tied_seats' => $tally->tiedSeats,
            'tie_kind' => $tally->tieKind,
            'casting_vote' => $decision === null ? null : [
                'chair' => $decision->chair,
                'note' => $decision->note,
                'elected' => array_map(fn (int $id): string => $names->get($id, ''), $decision->candidates),
                'tied' => array_map(fn (int $id): string => $names->get($id, ''), $decision->tied),
                'tied_seats' => $decision->tiedSeats,
                'recorded_at' => $decision->recordedAt,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The stored manifest hash, and whether the stored text still has it and
     * the tables still give it. The full check, signatures included, is
     * `election:verify`.
     *
     * The votes of an annulled round are not read any more, so for one only
     * the stored text is checked against its hash.
     *
     * @return Manifest|null
     */
    protected function manifestOf(ElectionRound $round, bool $annulled = false): ?array
    {
        $manifest = ElectionManifest::query()->where('election_round_id', $round->getKey())->first();

        if ($manifest === null) {
            return null;
        }

        if ($annulled) {
            return ['hash' => (string) $manifest->hash, 'intact' => hash_equals($manifest->hash, RoundManifest::hash($manifest->body))];
        }

        try {
            $stored = json_decode($manifest->body, true, flags: JSON_THROW_ON_ERROR);
            $rebuilt = $this->manifests->body($round, is_array($stored) ? ($stored['audit_head_hash'] ?? null) : null);
            $intact = hash_equals($manifest->hash, RoundManifest::hash($manifest->body))
                && hash_equals($manifest->hash, RoundManifest::hash($rebuilt));
        } catch (\Throwable) {
            $intact = false;
        }

        return ['hash' => (string) $manifest->hash, 'intact' => $intact];
    }

    /**
     * The reason the board gave for ending the round early or annulling it.
     */
    protected function reasonOf(ElectionRound $round): ?string
    {
        if ($round->opened_at === null && $round->annulled_at === null) {
            return null;
        }

        $entry = ElectionAuditEntry::query()
            ->where('election_round_id', $round->getKey())
            ->whereIn('action', [ElectionAuditAction::RoundClosedEarly->value, ElectionAuditAction::RoundAnnulled->value])
            ->orderByDesc('sequence')
            ->first();

        return is_array($entry?->details) ? ($entry->details['reason'] ?? null) : null;
    }
}
