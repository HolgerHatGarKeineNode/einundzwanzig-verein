<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
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
 * @phpstan-type ResultRow array{candidate_id: int, name: string, for: int, yes: int, no: int, elected: bool, tied: bool}
 * @phpstan-type Result array{outcome: ElectionOutcome, blank: int, tied_seats: int, rows: list<ResultRow>}
 * @phpstan-type Report array{round: ElectionRound, state: ElectionRoundState, sealed: bool, over_by_clock: bool, roll: int, cast: int, candidates: list<string>, result: Result|null, manifest: array{hash: string, intact: bool}|null, reason: string|null, run_off: array{candidates: list<string>, seats: int}|null, has_run_off: bool}
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

        if ($sealed) {
            $tally = $this->tally->handle($round);
            $rows = collect($tally->counts)
                ->map(fn (array $count, int $id): array => [
                    'candidate_id' => $id,
                    'name' => $names->get($id, ''),
                    'for' => $count['for'],
                    'yes' => $count['yes'],
                    'no' => $count['no'],
                    'elected' => in_array($id, $tally->elected, true),
                    'tied' => in_array($id, $tally->tied, true),
                ])
                ->sortByDesc(fn (array $row): int => ($row['elected'] ? 1_000_000 : 0) + max($row['for'], $row['yes']) * 1000 - $row['no'])
                ->values()
                ->all();

            $result = ['outcome' => $tally->outcome, 'blank' => $tally->blank, 'tied_seats' => $tally->tiedSeats, 'rows' => $rows];
            $manifest = $this->manifestOf($round);

            $proposal = $this->runOffs->proposal($round);

            if ($proposal['candidates'] !== []) {
                $runOff = [
                    'candidates' => array_map(fn (int $id): string => $names->get($id, ''), $proposal['candidates']),
                    'seats' => $proposal['seats'],
                ];
            }
        }

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
            'reason' => $this->reasonOf($round),
            'run_off' => $runOff,
            'has_run_off' => $round->children->whereNull('annulled_at')->isNotEmpty(),
        ];
    }

    /**
     * The stored manifest hash, and whether the stored text still has it and
     * the tables still give it. The full check, signatures included, is
     * `election:verify`.
     *
     * @return array{hash: string, intact: bool}|null
     */
    protected function manifestOf(ElectionRound $round): ?array
    {
        $manifest = ElectionManifest::query()->where('election_round_id', $round->getKey())->first();

        if ($manifest === null) {
            return null;
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
        if ($round->opened_at === null) {
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
