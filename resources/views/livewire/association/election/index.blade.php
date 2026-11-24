<?php

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionBallotRefusedException;
use App\Exceptions\ElectionBallotWriteException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\ElectionVote;
use App\Models\MemberProposal;
use App\Services\Election\Candidacies;
use App\Services\Election\CastBallot;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\TallyResult;
use App\Services\Election\TallyRound;
use App\Services\Election\VoteEvent;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const STATEMENT_MAX = 2000;

    /** Times are stored in UTC (app.timezone) and shown as members in Germany read them. */
    public const DISPLAY_TIMEZONE = 'Europe/Berlin';

    /**
     * What the rounds looked like at the last render, so a poll that finds
     * nothing new skips the render and leaves a half-filled ballot alone.
     */
    public string $fingerprint = '';

    /**
     * What happened to the member's ballot on this page, per round:
     * 'review' once the member reached the review step, 'now' when the cast
     * went through, 'late' when a retry found the vote already there,
     * 'closed' when the board closed the round before it arrived.
     * Round ids only — never a choice.
     *
     * @var array<int, string>
     */
    public array $notes = [];

    /**
     * Which election is shown when more than one is visible (the board sees
     * test elections next to the real one). Checked against the visible list
     * on every read, so a foreign id shows the default instead.
     */
    #[Url(as: 'wahl', except: null)]
    public ?int $electionId = null;

    public ?int $roundId = null;

    public string $statement = '';

    /**
     * The member behind the session, or null. Resolved on every request,
     * never stored on the component.
     */
    protected function viewer(): ?EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        return $pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() ? $pleb : null;
    }

    protected function can(string $ability, mixed $subject): bool
    {
        return Gate::forUser(NostrAuth::user())->allows($ability, $subject);
    }

    /**
     * The member's own pubkey, for the signer check in the browser.
     */
    #[Computed]
    public function viewerPubkey(): ?string
    {
        return $this->viewer()?->pubkey;
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->viewer() !== null;
    }

    /**
     * Announced real elections for everybody; test elections for the board
     * only, announced or not, so the board can rehearse before announcing.
     *
     * @return Collection<int, Election>
     */
    #[Computed]
    public function elections(): Collection
    {
        if (! $this->isMember) {
            return collect();
        }

        $isBoard = $this->viewer()->isBoardMember();

        return Election::query()
            ->where(function (Builder $query) use ($isBoard): void {
                $query->where(fn (Builder $real) => $real->where('is_test', false)->whereNotNull('announced_at'));

                if ($isBoard) {
                    $query->orWhere('is_test', true);
                }
            })
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Election $election): bool => $this->can('view', $election))
            ->values();
    }

    /**
     * The chosen election; else the real one before any test election (the
     * board reaches a test election through the switcher), the newest that
     * is not over first.
     */
    #[Computed]
    public function election(): ?Election
    {
        $elections = $this->elections;
        $real = $elections->reject(fn (Election $election): bool => $election->is_test);

        return $elections->firstWhere('id', $this->electionId)
            ?? $real->first(fn (Election $election): bool => $this->phaseOf($election) !== 'result')
            ?? $real->first()
            ?? $elections->first(fn (Election $election): bool => $this->phaseOf($election) !== 'result')
            ?? $elections->first();
    }

    /**
     * The rounds members stand in — run-offs are created in the meeting and
     * have no candidacy of their own.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function rounds(): Collection
    {
        if ($this->election === null) {
            return collect();
        }

        return $this->election->rounds()
            ->whereNull('parent_round_id')
            ->with(['candidates' => fn ($query) => $query
                ->where('status', ElectionCandidateStatus::Approved)
                ->with('pleb.profile')
                ->orderBy('id')])
            ->get();
    }

    protected function phaseOf(Election $election): string
    {
        $rounds = $election->rounds()->get();
        $now = now();

        if ($rounds->isEmpty()) {
            return 'announced';
        }

        if ($rounds->every(fn (ElectionRound $round): bool => $round->closed_at !== null || $round->annulled_at !== null)) {
            return 'result';
        }

        if ($rounds->contains(fn (ElectionRound $round): bool => $round->opened_at !== null)) {
            return 'meeting';
        }

        return $rounds->contains(fn (ElectionRound $round): bool => $round->parent_round_id === null && $round->stateAt($now) === ElectionRoundState::Draft)
            ? 'candidacy'
            : 'announced';
    }

    #[Computed]
    public function phase(): string
    {
        return $this->election === null ? 'none' : $this->phaseOf($this->election);
    }

    /**
     * Is the member entitled under the election's fee rule right now?
     */
    #[Computed]
    public function isEntitled(): bool
    {
        return $this->election !== null
            && app(ElectoralRoll::class)->reasonFor($this->election, $this->viewer(), now()) !== null;
    }

    /**
     * Why not, as one of: 'category' (passive or not admitted — paying does
     * not help), 'fee' (paying this year's fee in the portal helps),
     * 'fee-elsewhere' (the rule asks for a year the portal does not sell).
     */
    #[Computed]
    public function ineligibleBecause(): ?string
    {
        if ($this->election === null || $this->isEntitled) {
            return null;
        }

        if (! in_array($this->viewer()->association_status, ElectoralRoll::VOTING_STATUSES, true)) {
            return 'category';
        }

        $year = (int) now()->year;
        $feeYear = $this->election->fee_year;

        $payingNowHelps = match ($this->election->fee_rule) {
            ElectionFeeRule::Year => $feeYear === $year,
            ElectionFeeRule::YearOrPrevious => in_array($year, [$feeYear, $feeYear - 1], true),
            ElectionFeeRule::LastTwelveMonths => true,
        };

        return $payingNowHelps ? 'fee' : 'fee-elsewhere';
    }

    #[Computed]
    public function feeRuleText(): string
    {
        $election = $this->election;

        return match ($election?->fee_rule) {
            ElectionFeeRule::Year => __('den Beitrag für :year bezahlt', ['year' => $election->fee_year]),
            ElectionFeeRule::YearOrPrevious => __('den Beitrag für :year oder :previous bezahlt', ['year' => $election->fee_year, 'previous' => $election->fee_year - 1]),
            default => __('in den letzten zwölf Monaten einen Beitrag bezahlt'),
        };
    }

    /**
     * Every candidacy of the member in this election, whatever its state.
     *
     * @return Collection<int, ElectionCandidate>
     */
    #[Computed]
    public function ownCandidacies(): Collection
    {
        if ($this->rounds->isEmpty()) {
            return collect();
        }

        return ElectionCandidate::query()
            ->where('einundzwanzig_pleb_id', $this->viewer()->getKey())
            ->whereIn('election_round_id', $this->rounds->modelKeys())
            ->with('round')
            ->orderBy('id')
            ->get();
    }

    /**
     * The rounds the member may still stand in, as the policy says.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function openForCandidacy(): Collection
    {
        if ($this->phase !== 'candidacy') {
            return collect();
        }

        return $this->rounds->filter(fn (ElectionRound $round): bool => $this->can('submitCandidacy', $round))->values();
    }

    /**
     * The one thing this screen asks the member to do, or null. Paying comes
     * first: without it there is no vote and no approved candidacy.
     */
    #[Computed]
    public function primaryAction(): ?string
    {
        if ($this->election === null || $this->phase === 'result') {
            return null;
        }

        if ($this->ballotRounds->isNotEmpty()) {
            return 'ballot';
        }

        if ($this->ineligibleBecause === 'fee') {
            return 'pay';
        }

        // A further candidacy for another office is offered, not asked for.
        return $this->openForCandidacy->isNotEmpty() && $this->ownCandidacies->isEmpty() ? 'candidacy' : null;
    }

    /**
     * The member's own candidacies the policy still lets them withdraw.
     *
     * @return list<int>
     */
    #[Computed]
    public function withdrawableIds(): array
    {
        return $this->ownCandidacies
            ->filter(fn (ElectionCandidate $candidate): bool => $this->can('withdrawOwnCandidacy', $candidate))
            ->map(fn (ElectionCandidate $candidate): int => (int) $candidate->getKey())
            ->values()
            ->all();
    }

    public function submitCandidacy(): void
    {
        $rounds = $this->openForCandidacy;

        if ($rounds->count() === 1) {
            $this->roundId = (int) $rounds->first()->getKey();
        }

        $this->validate([
            'roundId' => ['required', 'integer', Rule::in($rounds->modelKeys())],
            'statement' => ['required', 'string', 'max:'.self::STATEMENT_MAX],
        ], [
            'roundId.required' => __('Wähle das Amt, für das du kandidierst.'),
            'roundId.in' => __('Für dieses Amt kannst du nicht mehr kandidieren.'),
            'statement.required' => __('Schreib ein paar Sätze über dich, damit die Mitglieder wissen, wen sie wählen.'),
            'statement.max' => __('Deine Vorstellung darf höchstens :max Zeichen lang sein.', ['max' => self::STATEMENT_MAX]),
        ]);

        try {
            app(Candidacies::class)->submit($rounds->firstWhere('id', $this->roundId), $this->viewer(), trim($this->statement));
        } catch (AuthorizationException) {
            $this->addError('roundId', __('Für dieses Amt kannst du nicht mehr kandidieren.'));

            return;
        }

        $this->reset('roundId', 'statement');
        $this->clearCaches();

        Flux::toast(__('Deine Kandidatur ist eingereicht. Der Vorstand gibt sie frei.'), variant: 'success');
    }

    public function withdrawCandidacy(int $candidateId): void
    {
        $candidate = $this->ownCandidacies->firstWhere('id', $candidateId);

        if ($candidate === null) {
            return;
        }

        try {
            app(Candidacies::class)->withdrawOwn($candidate, $this->viewer());
        } catch (AuthorizationException) {
            Flux::toast(__('Diese Kandidatur lässt sich nicht mehr zurückziehen.'), variant: 'danger');

            return;
        }

        Flux::modal('withdraw-'.$candidateId)->close();
        $this->clearCaches();

        Flux::toast(__('Deine Kandidatur ist zurückgezogen.'));
    }

    /**
     * Every round of the election with its approved candidates, a run-off
     * right after the round it belongs to.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function allRounds(): Collection
    {
        if ($this->election === null) {
            return collect();
        }

        $rounds = $this->election->rounds()
            ->with(['candidates' => fn ($query) => $query
                ->where('status', ElectionCandidateStatus::Approved)
                ->with('pleb.profile')
                ->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $runOffs = $rounds->whereNotNull('parent_round_id')->groupBy('parent_round_id');

        // Each first-level round, followed by every run-off below it at any depth.
        $withRunOffs = function (ElectionRound $round) use (&$withRunOffs, $runOffs): array {
            $chain = [$round];

            foreach ($runOffs->get($round->getKey())?->all() ?? [] as $runOff) {
                array_push($chain, ...$withRunOffs($runOff));
            }

            return $chain;
        };

        return $rounds->whereNull('parent_round_id')
            ->flatMap(fn (ElectionRound $round): array => $withRunOffs($round))
            ->values();
    }

    /**
     * Per round that has been opened: its state now, whether the member is
     * on its roll, and the id of the member's own vote event, if any. Roll
     * and votes are read in one query each, whatever the number of rounds:
     * the fingerprint asks this on every poll.
     *
     * @return array<int, array{state: ElectionRoundState, onRoll: bool, eventId: string|null}>
     */
    #[Computed]
    public function standing(): array
    {
        $viewer = $this->viewer();
        $now = now();
        $states = $this->allRounds->mapWithKeys(fn (ElectionRound $round): array => [(int) $round->getKey() => $round->stateAt($now)]);
        $openedIds = $states->reject(fn (ElectionRoundState $state): bool => $state === ElectionRoundState::Draft)->keys()->all();
        $onRoll = [];
        $eventIds = collect();

        if ($viewer !== null && $openedIds !== []) {
            $onRoll = array_flip(app(ElectoralRoll::class)->roundIdsContaining($viewer, $openedIds));
            $eventIds = ElectionVote::query()
                ->whereIn('election_round_id', $openedIds)
                ->where('einundzwanzig_pleb_id', $viewer->getKey())
                ->pluck('event_id', 'election_round_id');
        }

        return $states->map(fn (ElectionRoundState $state, int $id): array => [
            'state' => $state,
            'onRoll' => isset($onRoll[$id]),
            'eventId' => is_string($eventIds->get($id)) ? $eventIds->get($id) : null,
        ])->all();
    }

    /**
     * The open rounds the member can still vote in.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function ballotRounds(): Collection
    {
        return $this->allRounds->filter(function (ElectionRound $round): bool {
            $standing = $this->standing[$round->getKey()];

            return $standing['state'] === ElectionRoundState::Open && $standing['onRoll'] && $standing['eventId'] === null;
        })->values();
    }

    /**
     * The effective result of every sealed round whose result may be shown,
     * by round id: the count with the chair's casting vote applied.
     *
     * @return array<int, TallyResult>
     */
    #[Computed]
    public function results(): array
    {
        $results = [];

        foreach ($this->allRounds as $round) {
            if (! $round->isSealed() || ($round->reveal_at !== null && $round->reveal_at->isFuture())) {
                continue;
            }

            try {
                $results[$round->getKey()] = app(TallyRound::class)->effective($round);
            } catch (ElectionRoundStateException|\UnexpectedValueException) {
                // Not countable: the round shows as closed without numbers.
            }
        }

        return $results;
    }

    /**
     * Rounds that are sealed and revealed but whose stored votes could not be
     * counted (`results()` skipped them). They show "Ergebnis nicht lesbar"
     * instead of a promise that the result is coming; the board checks them.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadable(): array
    {
        return $this->allRounds
            ->filter(fn (ElectionRound $round): bool => $round->isSealed()
                && ($round->reveal_at === null || ! $round->reveal_at->isFuture())
                && ! isset($this->results[$round->getKey()]))
            ->map(fn (ElectionRound $round): int => (int) $round->getKey())
            ->values()
            ->all();
    }

    /**
     * Who holds which office after the election: per office, the names
     * elected in its round or that round's run-offs, and how many seats stay
     * empty. An annulled round that was repeated is folded into its repeat,
     * which carries the office's plain title; a struck round never ran.
     * `undecided` says the board may still add a run-off or a repeat, so the
     * page must not read as final. Null until every round is closed.
     *
     * @return list<array{title: string, seats: int, names: list<string>, annulled: bool, struck: bool, open: int, undecided: bool}>|null
     */
    #[Computed]
    public function outcome(): ?array
    {
        if ($this->phase !== 'result') {
            return null;
        }

        $stewardship = app(ElectionStewardship::class);
        $repeatSuffix = ' ('.__('Wiederholung').')';
        $rows = [];

        foreach ($this->allRounds->whereNull('parent_round_id') as $round) {
            $isAnnulled = $round->annulled_at !== null;

            // Repeated: the repeat's own row says what became of the office.
            if ($isAnnulled && $round->opened_at !== null && ! $stewardship->awaitsRepeat($round)) {
                continue;
            }

            $chain = $this->allRounds->filter(fn (ElectionRound $each): bool => $this->rootIdOf($each) === $round->getKey());
            $names = [];

            foreach ($chain as $each) {
                foreach ($this->results[$each->getKey()]->elected ?? [] as $candidateId) {
                    $names[] = $this->candidateName($each, $candidateId);
                }
            }

            $open = max(0, $round->seats - count($names));
            $last = $chain->reject(fn (ElectionRound $each): bool => $each->annulled_at !== null)->sortBy('id')->last();
            $lastResult = $last === null ? null : ($this->results[$last->getKey()] ?? null);

            $rows[] = [
                'title' => str_ends_with($round->title, $repeatSuffix) ? substr($round->title, 0, -strlen($repeatSuffix)) : $round->title,
                'seats' => $round->seats,
                'names' => $names,
                'annulled' => $isAnnulled && $round->opened_at !== null,
                'struck' => $isAnnulled && $round->opened_at === null,
                'open' => $open,
                'undecided' => $open > 0 && (
                    ($isAnnulled && $round->opened_at !== null)
                    || ($last !== null && in_array((int) $last->getKey(), $this->unreadable, true))
                    || ($lastResult !== null && $lastResult->outcome === ElectionOutcome::Tie && $lastResult->castingVote === null)
                    || ($lastResult !== null && $lastResult->outcome === ElectionOutcome::Understaffed && $lastResult->rule === ElectionCountingRule::SingleMajority && $lastResult->elected === [])
                ),
            ];
        }

        return $rows;
    }

    /**
     * The event ids of every vote in a round whose result is shown, sorted:
     * the published list a member finds their own "Kennung" in (plan
     * decision E4). Compared in the browser; there is no endpoint to ask.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function receipts(): array
    {
        $roundIds = array_keys($this->results);

        if ($roundIds === []) {
            return [];
        }

        return $this->allRounds
            ->filter(fn (ElectionRound $round): bool => in_array($round->id, $roundIds, true))
            ->mapWithKeys(fn (ElectionRound $round): array => [$round->id => $round->publishedVoteEventIds()])
            ->all();
    }

    /**
     * Open rounds whose roll the member is not on although the fee rule lets
     * them vote now: paid after the round opened outside the payment path,
     * or a status changed since. The top card must not say "Du darfst
     * wählen." next to the round that says the opposite.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function offRollOpenRounds(): Collection
    {
        if (! $this->isEntitled) {
            return collect();
        }

        return $this->allRounds->filter(function (ElectionRound $round): bool {
            $standing = $this->standing[$round->getKey()];

            return $standing['state'] === ElectionRoundState::Open && ! $standing['onRoll'];
        })->values();
    }

    /**
     * The id of the first-level round a run-off belongs to, at any depth.
     */
    protected function rootIdOf(ElectionRound $round): int
    {
        $byId = $this->allRounds->keyBy('id');
        $steps = 0;

        while ($round->parent_round_id !== null && $byId->has($round->parent_round_id) && $steps++ < $byId->count()) {
            $round = $byId->get($round->parent_round_id);
        }

        return (int) $round->getKey();
    }

    /**
     * What became of a round's run-offs, for its result card: 'decided' when
     * a run-off below it, at any depth, elected somebody; 'pending' while
     * there is one without that; null when there is none. An annulled
     * run-off does not count.
     */
    protected function runOffOf(ElectionRound $round): ?string
    {
        $byId = $this->allRounds->keyBy('id');
        $isBelow = function (ElectionRound $each) use ($byId, $round): bool {
            for ($steps = 0; $each->parent_round_id !== null && $steps < $byId->count(); $steps++) {
                if ($each->parent_round_id === $round->getKey()) {
                    return true;
                }

                $each = $byId->get($each->parent_round_id) ?? $each;
            }

            return false;
        };
        $runOffs = $this->allRounds->filter(fn (ElectionRound $each): bool => $each->annulled_at === null && $isBelow($each));

        if ($runOffs->isEmpty()) {
            return null;
        }

        return $runOffs->contains(fn (ElectionRound $each): bool => ($this->results[$each->getKey()]->elected ?? []) !== []) ? 'decided' : 'pending';
    }

    protected function candidateName(ElectionRound $round, int $candidateId): string
    {
        return MemberProposal::authorNameOf($round->candidates->firstWhere('id', $candidateId)?->pleb);
    }

    /**
     * A short, readable form of an event id: its first and last eight characters.
     */
    public function shortEventId(string $eventId): string
    {
        return substr($eventId, 0, 8).'…'.substr($eventId, -8);
    }

    public function displayTime(CarbonInterface $moment, string $format): string
    {
        return $moment->copy()->timezone(self::DISPLAY_TIMEZONE)->format($format);
    }

    /**
     * The live meeting as members may see it: the date once the election is
     * announced, the call link only on the meeting day (Berlin calendar) or
     * while a round is open.
     *
     * @return array{at: CarbonInterface|null, url: string|null}
     */
    #[Computed]
    public function meeting(): array
    {
        $election = $this->election;

        if ($election === null) {
            return ['at' => null, 'url' => null];
        }

        $at = $election->announced_at !== null ? $election->meeting_at : null;
        $isMeetingDay = $election->meeting_at !== null
            && $this->displayTime($election->meeting_at, 'Y-m-d') === $this->displayTime(now(), 'Y-m-d');
        $roundIsOpen = collect($this->standing)->contains(fn (array $standing): bool => $standing['state'] === ElectionRoundState::Open);
        $url = filled($election->call_url) && ($isMeetingDay || $roundIsOpen) ? $election->call_url : null;

        return ['at' => $at, 'url' => $url];
    }

    /**
     * The state the page was rendered from. A change in any of it re-renders.
     */
    protected function currentFingerprint(): string
    {
        return md5(json_encode([
            $this->election?->getKey(),
            $this->phase,
            collect($this->standing)->map(fn (array $standing): array => [$standing['state']->value, $standing['onRoll'], $standing['eventId'] !== null])->all(),
            // What the results are made of, never the results: a tally per sealed round on every poll was the cost of this page.
            $this->resultInputs(),
            [$this->meeting['at']?->getTimestamp(), $this->meeting['url']],
            $this->allRounds->map(fn (ElectionRound $round): array => [$round->getKey(), $round->candidates->count()])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Per round the columns and rows its shown result depends on: the seal,
     * an annulment, the reveal, the manifest and a recorded casting vote. A
     * change in any of them changes `results()`; reading them costs two
     * queries, whatever the number of rounds.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function resultInputs(): array
    {
        $sealedIds = $this->allRounds->filter(fn (ElectionRound $round): bool => $round->isSealed())->map(fn (ElectionRound $round): int => (int) $round->getKey())->values()->all();
        $manifests = $sealedIds === [] ? collect() : ElectionManifest::query()->whereIn('election_round_id', $sealedIds)->pluck('id', 'election_round_id');
        $castingVotes = $sealedIds === [] ? collect() : ElectionCastingVote::query()->whereIn('election_round_id', $sealedIds)->get(['id', 'election_round_id', 'created_at'])->keyBy('election_round_id');

        return $this->allRounds->map(fn (ElectionRound $round): array => [
            $round->getKey(),
            $round->closed_at?->getTimestamp(),
            $round->annulled_at?->getTimestamp(),
            $round->reveal_at?->getTimestamp(),
            $round->reveal_at !== null && $round->reveal_at->isFuture(),
            $manifests->get($round->getKey()),
            $castingVotes->get($round->getKey())?->getKey(),
            $castingVotes->get($round->getKey())?->created_at?->getTimestamp(),
        ])->all();
    }

    /**
     * Polled while the page is open: re-render only when the board opened or
     * closed a round, or anything else on it changed.
     */
    public function syncRounds(): void
    {
        $this->clearCaches();
        $fingerprint = $this->currentFingerprint();

        if ($fingerprint === $this->fingerprint) {
            $this->skipRender();

            return;
        }

        $this->fingerprint = $fingerprint;
    }

    /**
     * The open round the member may vote in, or a German reason why not.
     */
    protected function ballotRound(int $roundId): ElectionRound|string
    {
        $round = $this->allRounds->firstWhere('id', $roundId);
        $standing = $round === null ? null : $this->standing[$round->getKey()];

        return match (true) {
            $standing === null, $standing['state'] !== ElectionRoundState::Open => __('Der Vorstand hat diesen Wahlgang inzwischen geschlossen. Deine Stimme ist nicht abgegeben.'),
            ! $standing['onRoll'] => ElectionBallotRefusal::NotOnRoll->label(),
            $standing['eventId'] !== null => ElectionBallotRefusal::AlreadyVoted->label(),
            default => $round,
        };
    }

    /**
     * The unsigned vote event for `$selection` (candidate id => for|yes|no),
     * with a readable German summary as content — that text is what the
     * signer shows. `created_at` is the server's clock, so a member's device
     * clock cannot push the vote outside the open period.
     *
     * @param  array<array-key, mixed>  $selection
     * @return array{event?: array<string, mixed>, message?: string}
     */
    public function prepareBallot(int $roundId, array $selection): array
    {
        $this->skipRender();

        $round = $this->ballotRound($roundId);

        if (is_string($round)) {
            return ['message' => $round];
        }

        try {
            $round->counting_rule->ballotRows($selection, $round->candidates->modelKeys(), $round->seats);
        } catch (ElectionBallotRefusedException $refusal) {
            return ['message' => $refusal->reason->label()];
        }

        $event = VoteEvent::template($round, $selection, now()->getTimestamp());
        $event['content'] = $this->summary($round, $selection);
        $this->notes[$roundId] ??= 'review';

        return ['event' => $event];
    }

    /**
     * @param  array<int, string>  $selection
     */
    protected function summary(ElectionRound $round, array $selection): string
    {
        $lines = [
            __('Meine Stimme bei „:election“', ['election' => $this->election->name]),
            __('Wahlgang: :round', ['round' => $round->title]),
        ];

        if ($selection === []) {
            $lines[] = __('Enthaltung (leer)');

            return implode("\n", $lines);
        }

        foreach ($round->candidates as $candidate) {
            $choice = $selection[$candidate->getKey()] ?? null;
            $name = MemberProposal::authorNameOf($candidate->pleb);

            if ($choice === null && $round->counting_rule === ElectionCountingRule::YesNoPerCandidate) {
                $lines[] = __('Enthaltung: :name', ['name' => $name]);
            } elseif ($choice !== null) {
                $lines[] = match ($choice) {
                    'yes' => __('Ja: :name', ['name' => $name]),
                    'no' => __('Nein: :name', ['name' => $name]),
                    default => __('Für: :name', ['name' => $name]),
                };
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Take in the signed vote. Answers with a status the browser acts on:
     * 'counted' (the page re-renders into "gezählt"), 'voted' (another vote
     * of the member was already there), 'closed', or 'refused' with the
     * German reason.
     *
     * @param  array<string, mixed>  $event
     * @return array{status: string, message?: string}
     */
    public function castBallot(int $roundId, array $event): array
    {
        $round = $this->allRounds->firstWhere('id', $roundId);
        $viewer = $this->viewer();

        if ($round === null || $viewer === null) {
            $this->skipRender();

            return ['status' => 'refused', 'message' => ElectionBallotRefusal::RoundNotOpen->label()];
        }

        try {
            app(CastBallot::class)->handle($round, $viewer, $event);
        } catch (ElectionBallotRefusedException $refusal) {
            return $this->afterRefusal($round, $viewer, $refusal->reason, $event['id'] ?? null);
        } catch (ElectionBallotWriteException) {
            $this->skipRender();

            return ['status' => 'refused', 'message' => __('Deine Stimme konnte gerade nicht gespeichert werden. Bitte versuche es gleich noch einmal.')];
        }

        $this->notes[$roundId] = 'now';
        $this->refreshAfterVote();

        return ['status' => 'counted'];
    }

    /**
     * @return array{status: string, message?: string}
     */
    protected function afterRefusal(ElectionRound $round, EinundzwanzigPleb $viewer, ElectionBallotRefusal $reason, mixed $eventId): array
    {
        if ($reason === ElectionBallotRefusal::AlreadyVoted) {
            // A retry after a lost answer: the first attempt had arrived.
            $arrived = is_string($eventId) && $round->voteEventIdOf($viewer) === $eventId;

            if ($arrived) {
                $this->notes[$round->getKey()] = 'late';
            }

            $this->refreshAfterVote();

            return ['status' => $arrived ? 'counted' : 'voted'];
        }

        if ($reason === ElectionBallotRefusal::RoundNotOpen) {
            $this->notes[$round->getKey()] = 'closed';
            $this->refreshAfterVote();

            return ['status' => 'closed', 'message' => __('Der Vorstand hat diesen Wahlgang inzwischen geschlossen. Deine Stimme ist nicht abgegeben.')];
        }

        $this->skipRender();

        return ['status' => 'refused', 'message' => $reason->label()];
    }

    /**
     * After a lost answer: did the vote with `$eventId` arrive? 'counted',
     * 'voted' (a different vote of the member is there) or 'none'.
     */
    public function checkBallot(int $roundId, string $eventId): string
    {
        $round = $this->allRounds->firstWhere('id', $roundId);
        $stored = $round === null || $this->viewer() === null ? null : $round->voteEventIdOf($this->viewer());

        if ($stored === null) {
            $this->skipRender();

            return 'none';
        }

        if ($stored === $eventId) {
            $this->notes[$roundId] = 'late';
        }

        $this->refreshAfterVote();

        return $stored === $eventId ? 'counted' : 'voted';
    }

    protected function refreshAfterVote(): void
    {
        $this->clearCaches();
        $this->fingerprint = $this->currentFingerprint();
    }

    public function rendering(): void
    {
        if ($this->fingerprint === '' && $this->isMember) {
            $this->fingerprint = $this->currentFingerprint();
        }
    }

    protected function clearCaches(): void
    {
        unset($this->rounds, $this->allRounds, $this->standing, $this->ballotRounds, $this->results, $this->unreadable, $this->receipts, $this->offRollOpenRounds, $this->meeting, $this->outcome, $this->ownCandidacies, $this->withdrawableIds, $this->openForCandidacy, $this->primaryAction, $this->phase, $this->election, $this->elections);
    }
};
?>

@php
    $phaseLabels = [
        'announced' => __('Ankündigung'),
        'candidacy' => __('Kandidaturen'),
        'meeting' => __('Versammlung mit Live-Wahl'),
        'result' => __('Ergebnis'),
    ];
    $currentIndex = array_search($this->phase, ['announced', 'candidacy', 'meeting', 'result'], true);
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500';
@endphp

{{-- pb-32: the floating "Connect with Nostr" button of window.nostr.js sits
     at the bottom of the viewport; the last line must scroll clear of it.
     [&_:is(p,dd)]:max-w-prose: no line of running text holds more than 65
     characters (65ch of the monospace house font), wherever a paragraph sits
     (checklist Q12, 45–75). Pinned in tests/e2e/election-layout.spec.js. --}}
<div class="election-ui mx-auto w-full max-w-5xl pb-32 [&_:is(p,dd)]:max-w-prose" @if ($this->election !== null) wire:poll.5s="syncRounds" @endif>
    @if (! $this->isMember)
        <div class="mx-auto max-w-2xl space-y-6">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Die Wahl ist nur für Mitglieder sichtbar') }}</flux:callout.heading>
                <flux:callout.text>
                    @if (! \App\Support\NostrAuth::check())
                        {{ __('Bitte melde dich zunächst mit Nostr an.') }}
                    @else
                        {{ __('Zu deinem Nostr-Konto gibt es noch keine Mitgliedschaft.') }}
                    @endif
                </flux:callout.text>
                <x-slot name="actions">
                    @if (\App\Support\NostrAuth::check())
                        <flux:button :href="route('association.profile')" class="min-h-11" wire:navigate>{{ __('Meine Mitgliedschaft') }}</flux:button>
                    @else
                        {{-- The sidebar's login sits behind the menu on a phone; the page asks for it here. --}}
                        <livewire:auth-button location="election" :label="__('Mit Nostr anmelden')" :key="'election-login'" />
                    @endif
                </x-slot>
            </flux:callout>

            @if (! \App\Support\NostrAuth::check())
                {{-- Shown to every guest: the login button above reveals it on a
                     device without a signer instead of the vendor's English dialog. --}}
                <div x-data="{ missing: false }"
                     x-on:nostr-signer-missing.window="missing = true; $nextTick(() => { $refs.help.scrollIntoView({ block: 'start' }); $refs.help.focus({ preventScroll: true }) })">
                    <p role="status" class="text-base leading-normal text-text-primary">
                        <span x-show="missing" x-cloak class="mb-3 block">{{ __('Auf diesem Gerät ist noch kein Nostr-Signer eingerichtet. So bekommst du einen:') }}</span>
                    </p>
                    <x-election-signer-help id="guest-signer-help" x-ref="help"
                                            connect="window.dispatchEvent(new CustomEvent('nostr-login-start', { detail: { location: 'election' } }))" />
                </div>
            @endif
        </div>
    @else
        <x-page-header>
            <x-slot:title>{{ $this->election?->name ?? __('Wahl') }}</x-slot:title>
            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                @if ($this->election === null)
                    {{ __('Hier läuft die Vorstandswahl des Vereins, live in der Versammlung.') }}
                @else
                    {{ __('Gewählt wird live in der Versammlung im Video-Call. Der Vorstand leitet die Wahl.') }}
                @endif
            </p>

            @if ($this->elections->count() > 1)
                <nav aria-label="{{ __('Wahlen') }}" class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->elections as $choice)
                        <a href="{{ route('association.election', ['wahl' => $choice->id]) }}" wire:navigate
                           @if ($choice->is($this->election)) aria-current="page" @endif
                           @class([
                               'inline-flex min-h-11 max-w-full items-center gap-2 rounded-md border px-3 py-2 text-sm wrap-anywhere',
                               $focus,
                               'border-orange-500 text-text-primary' => $choice->is($this->election),
                               'border-border-default text-text-secondary hover:text-text-primary' => ! $choice->is($this->election),
                           ])>
                            @if ($choice->is_test)
                                <flux:icon.beaker variant="micro" class="shrink-0" aria-hidden="true" />
                            @endif
                            <span class="min-w-0">{{ $choice->name }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif
        </x-page-header>

        @if ($this->election?->is_test)
            <div role="note" class="mb-8 flex items-start gap-3 rounded-md border border-warning/60 bg-[repeating-linear-gradient(135deg,rgb(250_204_21/0.10)_0_12px,transparent_12px_24px)] px-4 py-3">
                <flux:icon.beaker class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                <p class="text-base text-text-primary">
                    <strong class="font-bold">{{ __('Testwahl.') }}</strong>
                    {{ __('Nur der Vorstand sieht sie. Nichts davon zählt.') }}
                </p>
            </div>
        @endif

        @if ($this->election === null)
            {{-- Empty state: nothing announced. The signer check is useful anyway. --}}
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_26rem]">
                <section class="rounded-lg border border-dashed border-border-default px-6 py-10" aria-labelledby="no-election">
                    <h2 id="no-election" class="text-xl font-bold text-text-primary">{{ __('Gerade steht keine Wahl an') }}</h2>
                    <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                        {{ __('Sobald der Vorstand eine Wahl ankündigt, stehen hier der Ablauf, die Kandidierenden und ob du wählen darfst.') }}
                    </p>
                </section>
                <x-election-signer-check :pubkey="$this->viewerPubkey" />
            </div>
        @else
            <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_26rem]">
                {{-- Your standing: first on a phone, the right column on a desk. --}}
                {{-- While a ballot is open, and while the receipt of a vote just
                     cast waits for its result, the round comes first on a phone
                     too: the receipt must not jump below the standing box. --}}
                {{-- Once a result is out, it comes first as well: on the meeting
                     day the member came for the result, not for the signer test. --}}
                @php
                    $roundFirst = $this->ballotRounds->isNotEmpty()
                        || $this->results !== []
                        || collect($this->standing)->contains(fn (array $each, int $id): bool => $each['eventId'] !== null && ! isset($this->results[$id]) && $each['state'] !== ElectionRoundState::Annulled);
                    $offRoll = $this->offRollOpenRounds->first();
                    $canVote = $this->isEntitled && $offRoll === null;
                @endphp
                <aside @class(['space-y-6 lg:col-start-2 lg:row-start-1', 'order-last lg:order-none' => $roundFirst]) aria-label="{{ __('Dein Stand') }}">
                    <section aria-labelledby="eligibility" @class([
                        'rounded-lg border p-5',
                        'border-success/40 bg-bg-surface' => $canVote,
                        'border-orange-700 bg-bg-surface' => ! $canVote,
                    ])>
                        {{-- The state icon sits beside the heading, not beside the sentence:
                             in the 26rem column an icon gutter left the sentence 43
                             characters wide (checklist Q12 wants 45). Full width it holds 46. --}}
                        <h2 id="eligibility" class="flex items-center gap-2 text-lg font-bold text-text-primary">
                            @if ($canVote)
                                <flux:icon.check-circle class="size-5 shrink-0 text-success" aria-hidden="true" />
                            @else
                                <flux:icon.exclamation-triangle class="size-5 shrink-0 text-orange-500" aria-hidden="true" />
                            @endif
                            {{ __('Deine Wahlberechtigung') }}
                        </h2>

                        @if ($offRoll !== null)
                            <p class="mt-3 break-words text-base text-text-primary" data-test="eligibility-off-roll">
                                {{ __('Für „:round“ stehst du nicht im Wählerverzeichnis.', ['round' => $offRoll->title]) }}
                            </p>
                            <p class="mt-2 text-sm leading-normal text-text-secondary">
                                {{ __('Nach der Beitragsregel darfst du wählen.') }}
                                {{ __('Melde dich im Call beim Vorstand, er kann dich nachtragen.') }}
                            </p>
                        @elseif ($this->isEntitled)
                            <p class="mt-3 text-base text-text-primary">{{ __('Du darfst wählen.') }}</p>
                            <p class="mt-2 text-sm leading-normal text-text-secondary">
                                {{ __('Wählen darf, wer aktives Mitglied oder Ehrenmitglied ist und :rule hat.', ['rule' => $this->feeRuleText]) }}
                            </p>
                        @else
                            <p class="mt-3 text-base text-text-primary">{{ __('Du darfst noch nicht wählen.') }}</p>
                            <p class="mt-2 text-sm leading-normal text-text-secondary">
                                @switch($this->ineligibleBecause)
                                    @case('category')
                                        {{ __('Wählen dürfen aktive Mitglieder und Ehrenmitglieder. Bei dir ist eine andere Mitgliedsart eingetragen. Melde dich beim Vorstand, wenn das nicht stimmt.') }}
                                        @break
                                    @case('fee-elsewhere')
                                        {{ __('Für diese Wahl brauchst du :rule. Das geht nicht über das Portal. Melde dich beim Vorstand.', ['rule' => str($this->feeRuleText)->replaceLast(' bezahlt', '')]) }}
                                        @break
                                    @default
                                        {{ __('Dafür fehlt dein Mitgliedsbeitrag: Wählen darf, wer :rule hat. Nach der Zahlung kommst du hierher zurück.', ['rule' => $this->feeRuleText]) }}
                                @endswitch
                            </p>
                            @if ($this->ineligibleBecause === 'fee')
                                <flux:button :href="route('association.profile', ['from' => 'election'])" variant="primary" icon="bolt" class="mt-4 min-h-11 w-full" wire:navigate>
                                    {{ __('Beitrag bezahlen') }}
                                </flux:button>
                            @endif
                        @endif
                    </section>

                    @unless ($this->phase === 'result')
                        <x-election-signer-check :pubkey="$this->viewerPubkey" />
                    @endunless
                </aside>

                {{-- The timeline is the page: one step is open, it holds the action. --}}
                <section class="lg:col-start-1 lg:row-start-1" aria-labelledby="timeline">
                    <h2 id="timeline" class="sr-only">{{ __('Ablauf der Wahl') }}</h2>
                    <ol class="relative">
                        @foreach ($phaseLabels as $key => $label)
                            @php
                                $index = $loop->index;
                                $isCurrent = $index === $currentIndex;
                                $isDone = $index < $currentIndex;
                            @endphp
                            <li wire:key="phase-{{ $key }}" @if ($isCurrent) aria-current="step" @endif
                                class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-x-4 pb-8 last:pb-0">
                                @unless ($loop->last)
                                    <span aria-hidden="true" @class([
                                        'absolute left-5 top-10 bottom-0 w-0.5 -translate-x-1/2',
                                        'bg-orange-500' => $isDone,
                                        'bg-border-default' => ! $isDone,
                                    ])></span>
                                @endunless
                                <span aria-hidden="true" @class([
                                    'relative z-10 flex size-10 items-center justify-center rounded-full border-2 text-base font-bold',
                                    'border-orange-500 bg-orange-500 text-accent-foreground' => $isDone,
                                    'border-orange-500 bg-bg-page text-orange-500' => $isCurrent,
                                    'border-neutral-500 bg-bg-page text-text-tertiary' => ! $isDone && ! $isCurrent,
                                ])>
                                    @if ($isDone)
                                        <flux:icon.check variant="mini" />
                                    @else
                                        {{ $index + 1 }}
                                    @endif
                                </span>

                                <div class="min-w-0 pt-1.5">
                                    <h3 @class([
                                        'text-lg font-bold leading-snug',
                                        'text-text-primary' => $isCurrent,
                                        'text-text-secondary' => ! $isCurrent,
                                    ])>
                                        {{ $label }}
                                        <span class="sr-only">
                                            @if ($isDone) {{ __('(erledigt)') }} @elseif ($isCurrent) {{ __('(jetzt)') }} @else {{ __('(folgt)') }} @endif
                                        </span>
                                    </h3>

                                    {{-- One line of state for every step, the open step gets the body. --}}
                                    @switch($key)
                                        @case('announced')
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if ($this->election->announced_at)
                                                    {{ __('Angekündigt am :date', ['date' => $this->displayTime($this->election->announced_at, 'd.m.Y')]) }}
                                                @elseif ($isDone)
                                                    {{ __('Übersprungen: Eine Testwahl wird nicht angekündigt') }}
                                                @else
                                                    {{ __('Noch nicht angekündigt') }}
                                                @endif
                                            </p>
                                            @if ($isCurrent)
                                                <p class="mt-3 max-w-prose text-base leading-normal text-text-primary">
                                                    {{ __('Der Vorstand legt gerade die Ämter fest. Danach kannst du hier kandidieren.') }}
                                                </p>
                                            @endif
                                            @break

                                        @case('candidacy')
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if ($isDone)
                                                    {{ __('Abgeschlossen') }}
                                                @elseif ($isCurrent)
                                                    {{ __('Offen bis zur Versammlung') }}
                                                @else
                                                    {{ __('Folgt, sobald die Ämter feststehen') }}
                                                @endif
                                            </p>
                                            @if ($isCurrent)
                                                <div class="mt-4 space-y-4">
                                                    @foreach ($this->ownCandidacies as $own)
                                                        <div wire:key="own-{{ $own->id }}" class="rounded-lg border border-border-default bg-bg-surface p-4">
                                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                                <p class="text-base font-bold text-text-primary">{{ __('Deine Kandidatur: :office', ['office' => $own->round->title]) }}</p>
                                                                <flux:badge size="sm"
                                                                            :color="match ($own->status) { ElectionCandidateStatus::Approved => 'green', ElectionCandidateStatus::Pending => 'yellow', default => 'zinc' }"
                                                                            :icon="match ($own->status) { ElectionCandidateStatus::Approved => 'check', ElectionCandidateStatus::Pending => 'clock', default => 'x-mark' }">
                                                                    {{ match ($own->status) { ElectionCandidateStatus::Approved => __('Freigegeben'), ElectionCandidateStatus::Pending => __('Wartet auf Freigabe'), ElectionCandidateStatus::Rejected => __('Nicht freigegeben'), ElectionCandidateStatus::Withdrawn => __('Zurückgezogen') } }}
                                                                </flux:badge>
                                                            </div>
                                                            @if ($own->status === ElectionCandidateStatus::Pending && ! $this->isEntitled)
                                                                <p class="mt-2 text-sm leading-normal text-text-secondary">{{ __('Der Vorstand gibt sie frei, sobald dein Beitrag bezahlt ist.') }}</p>
                                                            @elseif ($own->status === ElectionCandidateStatus::Approved)
                                                                <p class="mt-2 text-sm leading-normal text-text-secondary">{{ __('Du stehst unten in der Liste der Kandidierenden.') }}</p>
                                                            @endif
                                                            @if ($own->statement)
                                                                <p class="mt-3 line-clamp-3 whitespace-pre-line break-words text-sm leading-normal text-text-secondary">{{ $own->statement }}</p>
                                                            @endif

                                                            @if (in_array($own->id, $this->withdrawableIds, true))
                                                                <flux:modal.trigger :name="'withdraw-'.$own->id">
                                                                    <flux:button variant="ghost" icon="arrow-uturn-left" class="mt-3 min-h-11 -ml-3">{{ __('Kandidatur zurückziehen') }}</flux:button>
                                                                </flux:modal.trigger>
                                                                <flux:modal :name="'withdraw-'.$own->id" class="max-w-md">
                                                                    <div class="space-y-4">
                                                                        <flux:heading size="lg">{{ __('Kandidatur für :office zurückziehen?', ['office' => $own->round->title]) }}</flux:heading>
                                                                        <flux:text>{{ __('Für dieses Amt kannst du danach in dieser Wahl nicht noch einmal kandidieren.') }}</flux:text>
                                                                        <div class="flex flex-wrap justify-end gap-2">
                                                                            <flux:modal.close>
                                                                                <flux:button variant="ghost" class="min-h-11">{{ __('Behalten') }}</flux:button>
                                                                            </flux:modal.close>
                                                                            <flux:button variant="danger" class="min-h-11" wire:click="withdrawCandidacy({{ $own->id }})">{{ __('Zurückziehen') }}</flux:button>
                                                                        </div>
                                                                    </div>
                                                                </flux:modal>
                                                            @endif
                                                        </div>
                                                    @endforeach

                                                    @if ($this->openForCandidacy->isNotEmpty())
                                                        <form wire:submit="submitCandidacy" class="space-y-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-5">
                                                            <p class="max-w-prose text-base leading-normal text-text-primary">
                                                                @if ($this->ownCandidacies->isEmpty())
                                                                    {{ __('Du willst mitgestalten? Reich hier deine Kandidatur ein.') }}
                                                                    {{ __('Der Vorstand gibt sie frei.') }}
                                                                    {{ __('Dann sehen alle Mitglieder deine Vorstellung.') }}
                                                                @else
                                                                    {{ __('Du kannst zusätzlich für ein weiteres Amt kandidieren.') }}
                                                                @endif
                                                            </p>

                                                            @if ($this->openForCandidacy->count() > 1)
                                                                <flux:radio.group wire:model="roundId" :label="__('Amt')" variant="cards" class="flex-col">
                                                                    @foreach ($this->openForCandidacy as $round)
                                                                        <flux:radio wire:key="office-{{ $round->id }}" :value="$round->id" :label="$round->title" class="min-h-16 border border-neutral-500! data-checked:border-orange-500! data-checked:ring-1 data-checked:ring-orange-500"
                                                                                    :description="trans_choice(':count Sitz|:count Sitze', $round->seats, ['count' => $round->seats])" />
                                                                    @endforeach
                                                                </flux:radio.group>
                                                            @else
                                                                <p class="text-sm text-text-secondary">
                                                                    {{ __('Amt: :office', ['office' => $this->openForCandidacy->first()->title]) }}
                                                                </p>
                                                                <flux:error name="roundId" />
                                                            @endif

                                                            <flux:textarea wire:model="statement" rows="6" maxlength="2000" class="border-neutral-500!"
                                                                           :label="__('Deine Vorstellung')"
                                                                           :description="__('Wer bist du, was willst du im Amt anpacken? Höchstens 2000 Zeichen.')" />

                                                            <flux:button type="submit" :variant="$this->primaryAction === 'candidacy' ? 'primary' : 'filled'" icon="paper-airplane" class="min-h-11 w-full sm:w-auto">
                                                                {{ __('Kandidatur einreichen') }}
                                                            </flux:button>
                                                        </form>
                                                    @elseif ($this->ownCandidacies->isEmpty())
                                                        <p class="max-w-prose text-base leading-normal text-text-secondary">
                                                            {{ __('Kandidieren können aktive Mitglieder und Ehrenmitglieder.') }}
                                                        </p>
                                                    @endif
                                                </div>
                                            @endif
                                            @break

                                        @case('meeting')
                                            @php
                                                $opensAt = $this->rounds->pluck('opens_at')->filter()->sort()->first();
                                                $meetingAt = $this->meeting['at'] ?? $opensAt;
                                            @endphp
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if ($meetingAt)
                                                    {{ __('Am :date um :time Uhr', ['date' => $this->displayTime($meetingAt, 'd.m.Y'), 'time' => $this->displayTime($meetingAt, 'H:i')]) }}
                                                @else
                                                    {{ __('Termin und Link zum Call kommen vom Vorstand') }}
                                                @endif
                                            </p>
                                            @if ($this->meeting['url'])
                                                <p class="mt-3">
                                                    <a href="{{ $this->meeting['url'] }}" target="_blank" rel="noopener noreferrer" data-test="call-link"
                                                       class="inline-flex min-h-11 items-center gap-2 rounded-md border border-border-default bg-bg-surface px-4 text-base font-semibold text-text-primary hover:bg-bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                                        <flux:icon.video-camera class="size-5 shrink-0" aria-hidden="true" />
                                                        {{ __('Zum Video-Call') }}
                                                        <span class="sr-only">{{ __('(öffnet in neuem Tab)') }}</span>
                                                        <flux:icon.arrow-top-right-on-square class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                                                    </a>
                                                </p>
                                            @elseif ($this->meeting['at'] && ! $isDone)
                                                {{-- Only what holds: before the day the link waits for it; on the day without a link, say there is none yet. --}}
                                                @if ($isCurrent || $this->displayTime($this->meeting['at'], 'Y-m-d') <= $this->displayTime(now(), 'Y-m-d'))
                                                    <p class="mt-1 text-sm text-text-secondary" data-test="call-link-missing">{{ __('Der Vorstand hat noch keinen Link zum Call hinterlegt.') }}</p>
                                                @elseif (filled($this->election->call_url))
                                                    <p class="mt-1 text-sm text-text-secondary">{{ __('Den Link zum Call findest du am Sitzungstag hier.') }}</p>
                                                @endif
                                            @endif
                                            @if ($isCurrent && $this->ballotRounds->isEmpty())
                                                <p class="mt-3 max-w-prose text-base leading-normal text-text-primary">
                                                    {{ __('Der Vorstand öffnet die Wahlgänge nacheinander im Call. Der Stimmzettel erscheint dann hier, ohne dass du die Seite neu lädst.') }}
                                                </p>
                                            @endif
                                            @break

                                        @case('result')
                                            @php
                                                $undecided = collect($this->outcome ?? [])->where('undecided', true)->pluck('title');
                                            @endphp
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if (! $isCurrent)
                                                    {{ __('Direkt nach dem letzten Wahlgang') }}
                                                @elseif ($undecided->isNotEmpty())
                                                    {{ __('Alle bisherigen Wahlgänge sind geschlossen.') }}
                                                @else
                                                    {{ __('Alle Wahlgänge sind geschlossen.') }}
                                                @endif
                                            </p>
                                            @if ($isCurrent && $this->outcome !== null)
                                                <section class="mt-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-5" aria-labelledby="after-election" data-test="after-election">
                                                    <h4 id="after-election" class="text-lg font-bold text-text-primary">{{ $undecided->isNotEmpty() ? __('Stand jetzt') : __('Zustand nach der Wahl') }}</h4>
                                                    @if ($undecided->isNotEmpty())
                                                        {{-- Between a close and the run-off or repeat the board creates next, nothing here is final yet. --}}
                                                        <p class="mt-2 max-w-prose break-words text-base leading-normal text-text-primary" data-test="after-election-pending">
                                                            {{ trans_choice('Für :offices kann noch ein Wahlgang folgen.|Für :offices können noch Wahlgänge folgen.', $undecided->count(), ['offices' => $undecided->map(fn (string $title): string => '„'.$title.'“')->join(', ', ' und ')]) }}
                                                            {{ __('Ob, sagt der Vorstand im Call.') }}
                                                        </p>
                                                    @endif
                                                    {{-- The row decides by its own width, not the viewport's: beside the
                                                         26rem side column the card is narrow at 1280 and 1600 px, and a
                                                         12rem label left the dd 20 characters wide. Two columns only from
                                                         36rem: 12rem label + 1rem gap + 45 characters of the house font
                                                         (8 px each at 16 px) is 568 px. Below, the office stands over the name. --}}
                                                    <dl class="@container mt-3 divide-y divide-border-subtle">
                                                        @foreach ($this->outcome as $office)
                                                            <div wire:key="after-{{ $loop->index }}" class="grid gap-1 py-3 @min-[36rem]:grid-cols-[12rem_minmax(0,1fr)] @min-[36rem]:gap-4">
                                                                <dt class="break-words text-base text-text-secondary">{{ $office['title'] }}</dt>
                                                                <dd class="min-w-0 break-words text-base text-text-primary">
                                                                    @if ($office['struck'])
                                                                        {{ __('Wahlgang gestrichen, das Amt ist nicht besetzt') }}
                                                                    @elseif ($office['annulled'])
                                                                        {{ __('Wahlgang annulliert, das Amt ist nicht besetzt') }}
                                                                    @else
                                                                        @if ($office['names'] !== [])
                                                                            <span class="font-bold">{{ implode(', ', $office['names']) }}</span>
                                                                        @endif
                                                                        @if ($office['open'] > 0)
                                                                            <span @class(['block' => $office['names'] !== [], 'text-text-secondary'])>
                                                                                @if ($office['undecided'])
                                                                                    {{ trans_choice(':count Sitz ist noch offen|:count Sitze sind noch offen', $office['open'], ['count' => $office['open']]) }}
                                                                                @else
                                                                                    {{ trans_choice(':count Sitz bleibt unbesetzt|:count Sitze bleiben unbesetzt', $office['open'], ['count' => $office['open']]) }}
                                                                                @endif
                                                                            </span>
                                                                        @endif
                                                                    @endif
                                                                </dd>
                                                            </div>
                                                        @endforeach
                                                    </dl>
                                                </section>
                                            @endif
                                            @break
                                    @endswitch

                                    {{-- The rounds live in whichever step is open: during the meeting and after it. --}}
                                    @if ($isCurrent && in_array($key, ['meeting', 'result'], true) && $this->allRounds->isNotEmpty())
                                        <ol class="mt-6 space-y-6" aria-label="{{ __('Wahlgänge') }}">
                                            @foreach ($this->allRounds as $round)
                                                @php
                                                    $standing = $this->standing[$round->id];
                                                    $state = $standing['state'];
                                                    $result = $this->results[$round->id] ?? null;
                                                    $note = $this->notes[$round->id] ?? null;
                                                    $isBallot = $this->ballotRounds->contains('id', $round->id);
                                                @endphp
                                                <li wire:key="round-{{ $round->id }}-{{ $state->value }}-{{ $standing['eventId'] ? 'voted' : 'open' }}">
                                                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                                                        <h4 class="text-lg font-bold leading-snug text-text-primary">
                                                            {{ $round->title }}
                                                            <span class="ml-1 text-base font-normal text-text-secondary">{{ trans_choice(':count Sitz|:count Sitze', $round->seats, ['count' => $round->seats]) }}</span>
                                                        </h4>
                                                        <flux:badge size="sm"
                                                                    :color="match (true) { $result !== null => 'zinc', $state === ElectionRoundState::Open => 'green', $state === ElectionRoundState::Closed, $state === ElectionRoundState::Annulled => 'zinc', default => 'yellow' }"
                                                                    :icon="match (true) { $result !== null => 'check', $state === ElectionRoundState::Open => 'play', $state === ElectionRoundState::Closed => 'lock-closed', $state === ElectionRoundState::Annulled => 'x-mark', default => 'clock' }">
                                                            {{ match (true) { $result !== null => __('Ausgezählt'), $state === ElectionRoundState::Open => __('Offen'), $state === ElectionRoundState::Closed => __('Geschlossen'), $state === ElectionRoundState::Annulled => __('Annulliert'), default => __('Wartet') } }}
                                                        </flux:badge>
                                                    </div>

                                                    @if ($isBallot)
                                                        <x-election-ballot :round="$round" :election="$this->election" :pubkey="$this->viewerPubkey" />
                                                    @elseif (in_array($round->id, $this->unreadable, true))
                                                        {{-- Sealed, but the stored votes cannot be counted. Calm, no command: the board checks it. --}}
                                                        <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary" data-test="unreadable-{{ $round->id }}">
                                                            {{ __('Ergebnis nicht lesbar. Der Vorstand prüft das.') }}
                                                        </p>
                                                    @elseif ($standing['eventId'] !== null && $result === null && $state !== ElectionRoundState::Annulled)
                                                        {{-- Cast on this page: bring the receipt into view and give it the focus. --}}
                                                        <div role="status" class="mt-3 rounded-lg border border-success/50 bg-bg-surface p-4 sm:p-5"
                                                             @if (in_array($note, ['now', 'late'], true))
                                                                 x-data x-init="$nextTick(() => { $refs.receipt.scrollIntoView({ block: 'center' }); $refs.receipt.focus({ preventScroll: true }) })"
                                                             @endif>
                                                            <h5 x-ref="receipt" tabindex="-1" data-receipt
                                                                class="flex items-start gap-3 rounded-sm text-lg font-bold leading-snug text-text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-orange-500">
                                                                <flux:icon.check-circle class="mt-0.5 size-6 shrink-0 text-success" aria-hidden="true" />
                                                                {{-- Arrived, not counted: counting happens when the board closes the round. --}}
                                                                <span>
                                                                    @if ($note === 'late')
                                                                        {{ __('Deine Stimme war schon angekommen.') }}
                                                                    @elseif ($note === 'now')
                                                                        {{ __('Deine Stimme ist angekommen.') }}
                                                                    @else
                                                                        {{ __('Du hast in diesem Wahlgang schon gewählt.') }}
                                                                    @endif
                                                                </span>
                                                            </h5>
                                                            <p class="mt-2 text-sm leading-normal text-text-secondary sm:pl-9">
                                                                {{ __('Kennung deiner Stimme:') }} <span class="font-bold text-text-primary tabular-nums" data-test="receipt-id">{{ $this->shortEventId($standing['eventId']) }}</span>
                                                                <span class="block">{{ __('Nach dem Schließen steht sie hier in der Liste aller Kennungen.') }}</span>
                                                            </p>
                                                            <p class="mt-1 text-sm leading-normal text-text-secondary sm:pl-9">
                                                                {{ $state === ElectionRoundState::Open ? __('Das Ergebnis steht hier, sobald der Vorstand den Wahlgang schließt.') : __('Der Wahlgang ist geschlossen. Das Ergebnis steht hier, sobald es ausgezählt ist.') }}
                                                            </p>
                                                        </div>
                                                    @elseif ($state === ElectionRoundState::Open && ! $standing['onRoll'])
                                                        <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                                                            {{ __('Du stehst für diesen Wahlgang nicht im Wählerverzeichnis.') }}
                                                            @if ($this->ineligibleBecause === 'fee')
                                                                {{ __('Bezahlst du, solange er offen ist, kommst du automatisch dazu.') }}
                                                                {{ __('Dann erscheint hier dein Stimmzettel.') }}
                                                            @else
                                                                {{ __('Ins Verzeichnis kommt, wer beim Öffnen wählen darf.') }}
                                                                {{ __('Stimmt das bei dir nicht, melde dich im Call beim Vorstand.') }}
                                                            @endif
                                                        </p>
                                                        @if ($this->ineligibleBecause === 'fee')
                                                            <p class="mt-2">
                                                                <a href="{{ route('association.profile', ['from' => 'election']) }}" wire:navigate
                                                                   class="inline-flex min-h-11 items-center text-base font-semibold text-orange-500 underline underline-offset-4 hover:text-text-primary {{ $focus }}">{{ __('Jetzt Beitrag bezahlen') }}</a>
                                                            </p>
                                                        @endif
                                                    @elseif ($note === 'closed')
                                                        <p role="status" class="mt-2 max-w-prose text-base leading-normal text-text-primary">
                                                            {{ __('Der Vorstand hat diesen Wahlgang geschlossen, bevor deine Stimme ankam. Sie ist nicht gezählt.') }}
                                                        </p>
                                                    @elseif ($note === 'review' && $state === ElectionRoundState::Closed && $result !== null && $standing['eventId'] === null)
                                                        {{-- The poll replaced the sheet the member was still checking. --}}
                                                        <p role="status" class="mt-2 max-w-prose text-base leading-normal text-text-primary">
                                                            {{ __('Der Wahlgang wurde geschlossen, bevor du abgegeben hast. Deine Stimme ist nicht dabei.') }}
                                                        </p>
                                                    @elseif ($state === ElectionRoundState::Closed && $result === null)
                                                        <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                                                            @if ($note === 'review' && $standing['eventId'] === null)
                                                                <span role="status" class="text-text-primary">{{ __('Der Wahlgang wurde geschlossen, bevor du abgegeben hast. Deine Stimme ist nicht dabei.') }}</span>
                                                            @elseif ($standing['onRoll'])
                                                                {{ __('Ohne deine Stimme geschlossen.') }}
                                                            @endif
                                                            {{ $round->isSealed() && $round->reveal_at ? __('Das Ergebnis steht hier ab :time Uhr.', ['time' => $this->displayTime($round->reveal_at, 'd.m.Y H:i')]) : __('Das Ergebnis steht hier, sobald es ausgezählt ist.') }}
                                                        </p>
                                                    @elseif ($state === ElectionRoundState::Annulled)
                                                        {{-- The minutes' words: a counted round keeps its result, marked invalid. --}}
                                                        <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary" data-test="annulled-{{ $round->id }}">
                                                            @if ($round->opened_at === null)
                                                                {{ __('Der Vorstand hat diesen Wahlgang gestrichen.') }}
                                                            @elseif ($round->closed_at !== null)
                                                                {{ __('Annulliert nach dem Auszählen.') }}
                                                                {{ __('Das ausgezählte Ergebnis ist ungültig.') }}
                                                            @else
                                                                {{ __('Der Vorstand hat diesen Wahlgang annulliert.') }}
                                                                {{ __('Er wird nicht ausgezählt.') }}
                                                            @endif
                                                        </p>
                                                    @endif

                                                    @if ($result !== null)
                                                        <x-election-round-result :round="$round" :result="$result" :ballots="$round->votes()->count()" :voted="$standing['eventId'] !== null" :run-off="$this->runOffOf($round)"
                                                                                 :receipts="$this->receipts[$round->id] ?? []" :own-event-id="$standing['eventId']" />
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            @if ($this->rounds->isNotEmpty())
                <section class="mt-12" aria-labelledby="candidates">
                    <h2 id="candidates" class="text-2xl font-bold text-text-primary">{{ __('Kandidierende') }}</h2>
                    <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                        {{ __('Hier stehen alle Kandidaturen, die der Vorstand freigegeben hat.') }}
                    </p>

                    <div class="mt-6 space-y-10">
                        @foreach ($this->rounds as $round)
                            <div wire:key="office-list-{{ $round->id }}">
                                <h3 class="text-xl font-bold text-text-primary">
                                    {{ $round->title }}
                                    <span class="ml-2 text-base font-normal text-text-secondary">{{ trans_choice(':count Sitz|:count Sitze', $round->seats, ['count' => $round->seats]) }}</span>
                                </h3>

                                @if ($round->candidates->isEmpty())
                                    <p class="mt-3 text-base text-text-secondary">{{ __('Noch keine freigegebene Kandidatur.') }}</p>
                                @else
                                    <ul class="mt-4 divide-y divide-border-subtle border-y border-border-subtle">
                                        @foreach ($round->candidates as $candidate)
                                            @php $name = \App\Models\MemberProposal::authorNameOf($candidate->pleb); @endphp
                                            <li wire:key="candidate-{{ $candidate->id }}">
                                                <details class="group">
                                                    <summary class="flex min-h-16 cursor-pointer list-none items-center gap-4 py-3 hover:bg-bg-surface focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500 [&::-webkit-details-marker]:hidden">
                                                        <flux:avatar circle size="lg" :src="$candidate->pleb?->profile?->picture ?: null" :name="$name" class="shrink-0" />
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block break-words text-lg font-bold leading-snug text-text-primary">{{ $name }}</span>
                                                            <span class="block text-sm text-text-secondary">
                                                                <span class="group-open:hidden">{{ __('Vorstellung lesen') }}</span>
                                                                <span class="hidden group-open:inline">{{ __('Vorstellung schließen') }}</span>
                                                            </span>
                                                        </span>
                                                        <flux:icon.chevron-down class="mr-1 size-5 shrink-0 text-text-secondary transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                                                    </summary>
                                                    <div class="pb-6 pl-0 sm:pl-16">
                                                        @if ($candidate->statement)
                                                            <p class="max-w-prose whitespace-pre-line break-words text-base leading-relaxed text-text-primary">{{ $candidate->statement }}</p>
                                                        @else
                                                            <p class="text-base text-text-secondary">{{ __('Keine Vorstellung hinterlegt.') }}</p>
                                                        @endif
                                                    </div>
                                                </details>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    @endif
</div>
