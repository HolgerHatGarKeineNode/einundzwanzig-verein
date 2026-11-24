<?php

use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Livewire\Traits\WithViewer;
use App\Models\Election;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\RefusalText;
use App\Services\Election\RoundReport;
use App\Services\Election\TallyRound;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The meeting on the board's election page: every round of the election with
 * its state, live turnout and result, and the dialogs that open, close,
 * annul, strike and repeat a round, seal it, and decide its tie. Counts
 * only, never a vote.
 *
 * A child of `association.election.admin`, mounted for the selected
 * election on every tab: the run sheet of the page takes its steps through
 * the events below, so their dialogs must be there. The list shows on the
 * "Sitzung" tab only (`$visible`); a step taken from another tab asks the
 * page for that tab with `show-section`. After an act it tells the page
 * `election-changed`, so the run sheet follows.
 *
 * Callable on its own like every Livewire component: each public method asks
 * the board guard itself, and a round of another election is not found.
 */
new class extends Component
{
    use WithViewer;

    private const DEFAULT_CLOSE_REASON = 'Abstimmung in der Sitzung beendet.';

    #[Locked]
    public int $electionId;

    /** The page shows the "Sitzung" tab. */
    #[Reactive]
    public bool $visible = false;

    #[Locked]
    public ?int $pendingRoundId = null;

    /** 'open', 'close', 'annul', 'strike' or 'repeat' — what the round modal confirms. */
    #[Locked]
    public ?string $pendingRoundAction = null;

    public string $roundReason = '';

    /** The tied round the casting-vote or tie run-off dialog is about. */
    #[Locked]
    public ?int $tieRoundId = null;

    /** @var list<int|string> candidate ids the chair decided for */
    public array $castingCandidates = [];

    public string $castingChair = '';

    /** In a yes-equals-no tie an empty selection decides everyone "Nein"; the chair must confirm that on purpose. */
    public bool $castingAllNo = false;

    public string $castingNote = '';

    public bool $runOffResolved = false;

    public function mount(int $electionId): void
    {
        $this->boardActor();

        $this->electionId = $electionId;
    }

    /**
     * Ask before a round is opened, ended, annulled or repeated. Ending and
     * annulling take a reason, which goes into the audit log.
     */
    #[On('start-round-action')]
    public function startRoundAction(int $roundId, string $kind): void
    {
        $this->boardActor();

        $round = $this->round($roundId);

        $this->pendingRoundId = (int) $round->id;
        $this->pendingRoundAction = in_array($kind, ['open', 'close', 'annul', 'strike', 'repeat'], true) ? $kind : 'open';
        $this->roundReason = $this->pendingRoundAction === 'close' ? __(self::DEFAULT_CLOSE_REASON) : '';
        $this->resetErrorBag('roundReason');
        $this->showLive();

        Flux::modal('round-action')->show();
    }

    public function confirmRoundAction(ElectionStewardship $stewardship): void
    {
        $actor = $this->boardActor();

        $round = $this->round($this->pendingRoundId ?? abort(404));
        $action = $this->pendingRoundAction ?? 'open';

        if ($action === 'open' && ($openRound = $this->openRoundBesides($round)) !== null) {
            $this->addError('roundReason', __('„:open“ ist noch offen. Es ist immer nur ein Wahlgang offen: Schließt „:open“ zuerst, dann lässt sich „:round“ öffnen.', ['open' => $openRound->title, 'round' => $round->title]));

            return;
        }

        if (in_array($action, ['close', 'annul', 'strike'], true)) {
            $this->validate(
                ['roundReason' => ['required', 'string', 'min:3', 'max:1000']],
                [
                    'roundReason.required' => __('Nenne einen Grund. Er steht im Protokoll der Wahl.'),
                    'roundReason.min' => __('Nenne einen Grund mit mindestens drei Zeichen.'),
                    'roundReason.max' => __('Der Grund darf höchstens 1000 Zeichen lang sein.'),
                ],
            );
        }

        try {
            $result = match ($action) {
                'close' => $stewardship->closeEarly($round, $actor, trim($this->roundReason)),
                'annul' => $stewardship->annul($round, $actor, trim($this->roundReason)),
                'strike' => $stewardship->strike($round, $actor, trim($this->roundReason)),
                'repeat' => $stewardship->repeat($round, $actor),
                default => $stewardship->open($round, $actor),
            };
        } catch (ElectionRoundStateException $exception) {
            $this->addError('roundReason', app(RefusalText::class)->of($action, $exception, $this->election));

            return;
        } catch (ElectionActionRefusedException) {
            $this->addError('roundReason', __('„:round“ ist schon wiederholt oder nicht annulliert. Lade die Seite neu, vielleicht hat ihn schon jemand wiederholt.', ['round' => $round->title]));

            return;
        }

        Flux::modal('round-action')->close();
        $this->pendingRoundId = null;
        $this->pendingRoundAction = null;
        $this->roundReason = '';
        $this->acted();

        Flux::toast(match ($action) {
            'close' => __('„:round“ ist geschlossen und ausgezählt.', ['round' => $round->title]),
            'annul' => __('„:round“ ist annulliert.', ['round' => $round->title]),
            'strike' => __('„:round“ ist gestrichen.', ['round' => $round->title]),
            'repeat' => __('„:round“ ist angelegt. Öffnet ihn, wenn alle bereit sind.', ['round' => $result->title]),
            default => __('„:round“ ist offen. Die Wahlberechtigten können jetzt abstimmen.', ['round' => $round->title]),
        }, variant: in_array($action, ['annul', 'strike'], true) ? 'warning' : 'success');
    }

    /**
     * Seal a round whose 60 minutes ran out before anybody closed it.
     */
    #[On('seal-round')]
    public function sealRound(int $roundId, ElectionStewardship $stewardship): void
    {
        $actor = $this->boardActor();
        $round = $this->round($roundId);

        try {
            $stewardship->close($round, $actor);
        } catch (ElectionRoundStateException) {
            Flux::toast(__('„:round“ nimmt noch Stimmen an. Schließt ihn über „Wahlgang schließen“.', ['round' => $round->title]), variant: 'danger');

            return;
        }

        $this->acted();
        $this->showLive();
        Flux::toast(__('„:round“ ist ausgezählt.', ['round' => $round->title]), variant: 'success');
    }

    /**
     * Open the dialog in which the election officer enters the chair's
     * casting vote on the tie of a sealed round (statutes Art. 6).
     */
    #[On('start-casting-vote')]
    public function startCastingVote(int $roundId): void
    {
        $this->boardActor();
        $round = $this->tieRound($roundId);

        if ($round === null) {
            return;
        }

        $this->tieRoundId = (int) $round->id;
        $this->castingCandidates = [];
        $this->castingChair = '';
        $this->castingAllNo = false;
        $this->castingNote = '';
        $this->resetErrorBag(['castingCandidates', 'castingChair', 'castingNote']);
        $this->showLive();

        Flux::modal('casting-vote')->show();
    }

    public function recordCastingVote(RecordCastingVote $castingVotes, TallyRound $tally): void
    {
        $actor = $this->boardActor();
        $round = $this->round($this->tieRoundId ?? abort(404));

        // The tally refuses a round annulled while the dialog was open, so it
        // sits in the same try as the recording and reaches the same refusal.
        try {
            $result = $tally->handle($round);
            $exact = $result->tieKind === ElectionTieKind::Candidates;

            $this->validate(
                [
                    'castingCandidates' => array_filter(['array', $exact ? 'size:'.$result->tiedSeats : 'max:'.$result->tiedSeats, $exact ? 'required' : null]),
                    'castingCandidates.*' => ['integer', Rule::in($result->tied)],
                    'castingChair' => ['required', 'string', 'min:2', 'max:'.RecordCastingVote::MAX_CHAIR_LENGTH],
                    'castingNote' => ['nullable', 'string', 'max:'.ElectionAuditLog::MAX_REASON_LENGTH],
                ],
                [
                    'castingCandidates.required' => trans_choice('{1} Wähle die Person, für die die/der Vorsitzende entschieden hat.|[2,*] Wähle die :count Personen, für die die/der Vorsitzende entschieden hat.', $result->tiedSeats, ['count' => $result->tiedSeats]),
                    'castingCandidates.size' => trans_choice('{1} Wähle genau eine Person: Es geht um einen Sitz.|[2,*] Wähle genau :count Personen: Es geht um :count Sitze.', $result->tiedSeats, ['count' => $result->tiedSeats]),
                    'castingCandidates.max' => trans_choice('{1} Höchstens eine Person kann mit Ja bestätigt werden: Es ist ein Sitz frei.|[2,*] Höchstens :count Personen können mit Ja bestätigt werden: Es sind :count Sitze frei.', $result->tiedSeats, ['count' => $result->tiedSeats]),
                    'castingCandidates.*' => __('Wähle nur Personen aus dem Gleichstand.'),
                    'castingChair.required' => __('Nenne die/den Vorsitzenden. Der Name steht im Protokoll.'),
                    'castingChair.min' => __('Nenne die/den Vorsitzenden mit mindestens zwei Zeichen.'),
                    'castingChair.max' => __('Der Name darf höchstens :max Zeichen lang sein.', ['max' => RecordCastingVote::MAX_CHAIR_LENGTH]),
                    'castingNote.max' => __('Die Anmerkung darf höchstens :max Zeichen lang sein.', ['max' => ElectionAuditLog::MAX_REASON_LENGTH]),
                ],
            );

            if (! $exact && $this->castingCandidates === [] && ! $this->castingAllNo) {
                $this->addError('castingCandidates', __('Niemand ist angehakt. Hake an, wen die/der Vorsitzende mit Ja bestätigt, oder bestätige unten, dass alle mit Nein entschieden sind.'));

                return;
            }

            if (! $exact && $this->castingCandidates !== [] && $this->castingAllNo) {
                $this->addError('castingCandidates', __('Du hast Personen angehakt und zugleich „alle mit Nein“ bestätigt. Entscheide dich für eines.'));

                return;
            }

            $castingVotes->handle($round, $actor, array_map(intval(...), $this->castingCandidates), $this->castingChair, $this->castingNote);
        } catch (ElectionCandidateBlockedException $refusal) {
            $this->addError('castingCandidates', app(RefusalText::class)->of('casting', $refusal, $this->election));

            return;
        } catch (\UnexpectedValueException) {
            $this->addError('castingCandidates', app(RefusalText::class)->unreadable($round));

            return;
        } catch (ElectionActionRefusedException|ElectionRoundStateException) {
            $this->addError('castingCandidates', __('Für „:round“ lässt sich kein Stichentscheid eintragen: Er ist schon eingetragen, es gibt eine Stichwahl, oder der Wahlgang ist annulliert. Lade die Seite neu.', ['round' => $round->title]));

            return;
        }

        Flux::modal('casting-vote')->close();
        $this->tieRoundId = null;
        $this->castingCandidates = [];
        $this->castingChair = '';
        $this->castingAllNo = false;
        $this->castingNote = '';
        $this->acted();

        Flux::toast(__('Der Stichentscheid zu „:round“ ist eingetragen.', ['round' => $round->title]), variant: 'success');
    }

    /**
     * Open the dialog for the run-off of a tie: the statutes have the chair
     * decide, so a run-off only follows a resolution of the assembly.
     */
    #[On('start-tie-run-off')]
    public function startTieRunOff(int $roundId): void
    {
        $this->boardActor();
        $round = $this->tieRound($roundId);

        if ($round === null) {
            return;
        }

        $this->tieRoundId = (int) $round->id;
        $this->runOffResolved = false;
        $this->resetErrorBag('runOffResolved');
        $this->showLive();

        Flux::modal('tie-run-off')->show();
    }

    public function confirmTieRunOff(CreateRunOff $runOffs): void
    {
        $actor = $this->boardActor();
        $round = $this->round($this->tieRoundId ?? abort(404));

        $this->validate(
            ['runOffResolved' => ['accepted']],
            ['runOffResolved.accepted' => __('Eine Stichwahl gibt es bei Gleichstand nur, wenn die Versammlung sie beschlossen hat. Sonst fällt die/der Vorsitzende den Stichentscheid.')],
        );

        try {
            $runOff = $runOffs->handle($round, $actor, resolvedByAssembly: true);
        } catch (ElectionActionRefusedException|ElectionRoundStateException $exception) {
            $this->addError('runOffResolved', str_contains($exception->getMessage(), 'cannot be counted')
                ? app(RefusalText::class)->unreadableRunOff($round)
                : __('Für „:round“ gibt es keine Stichwahl anzulegen: Der Stichentscheid ist schon eingetragen, oder es gibt die Stichwahl schon. Lade die Seite neu.', ['round' => $round->title]));

            return;
        }

        Flux::modal('tie-run-off')->close();
        $this->tieRoundId = null;
        $this->runOffResolved = false;
        $this->acted();

        Flux::toast(__('„:round“ ist angelegt. Öffnet sie, wenn alle bereit sind.', ['round' => $runOff->title]), variant: 'success');
    }

    #[On('create-run-off')]
    public function createRunOff(int $roundId, CreateRunOff $runOffs): void
    {
        $actor = $this->boardActor();
        $round = $this->round($roundId);

        try {
            // A tie needs the assembly's resolution, given in the dialog of startTieRunOff(); here it is refused.
            $runOff = $runOffs->handle($round, $actor);
        } catch (ElectionActionRefusedException|ElectionRoundStateException $exception) {
            Flux::toast(str_contains($exception->getMessage(), 'cannot be counted')
                ? app(RefusalText::class)->unreadableRunOff($round)
                : __('Für „:round“ gibt es keine Stichwahl anzulegen.', ['round' => $round->title]), variant: 'danger');

            return;
        }

        $this->acted();
        $this->showLive();
        Flux::toast(__('„:round“ ist angelegt. Öffnet sie, wenn alle bereit sind.', ['round' => $runOff->title]), variant: 'success');
    }

    /**
     * Another board member, or the turnout poll, saw a round change.
     */
    #[On('election-round-changed')]
    public function refreshRounds(): void
    {
        $this->boardActor();

        unset($this->election, $this->roundReports);
    }

    #[Computed]
    public function election(): Election
    {
        $this->boardActor();

        return Election::query()->findOrFail($this->electionId);
    }

    /**
     * The rounds of the election with state, turnout and result.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function roundReports(): Collection
    {
        $this->boardActor();

        return app(RoundReport::class)->forElection($this->election);
    }

    protected function clock(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : DisplayTime::of($moment)->format('H:i');
    }

    /**
     * The confirm text of the round modal: what happens, in numbers.
     */
    protected function pendingRoundText(ElectionRound $round): string
    {
        return match ($this->pendingRoundAction) {
            'close' => $this->turnoutText($round).' '.__('Danach nimmt der Wahlgang keine Stimmen mehr an und wird sofort ausgezählt. Das lässt sich nicht zurücknehmen.'),
            'strike' => __('„:round“ wird nie geöffnet und zählt wie ein annullierter Wahlgang: Niemand stimmt darüber ab. Das lässt sich nicht zurücknehmen. Der Grund steht im Protokoll.', ['round' => $round->title]),
            // A counted round keeps its result in the minutes, marked as void: "never counted" would be false there.
            'annul' => $round->isSealed()
                ? __('Das ausgezählte Ergebnis wird ungültig. Es bleibt im Beschlussprotokoll stehen, markiert als „ungültig“, mit dem Grund. Der Wahlgang lässt sich nicht wieder öffnen.')
                : __('Die abgegebenen Stimmen werden nie ausgezählt, und der Wahlgang lässt sich nicht wieder öffnen. Der Grund steht im Protokoll.'),
            'repeat' => __('Legt „:title“ als neuen Wahlgang an, mit denselben freigegebenen Kandidaturen, derselben Zählregel und denselben Sitzen. Abgestimmt wird erst, wenn ihr ihn öffnet.', ['title' => ElectionStewardship::repeatTitle($round->title)]),
            default => __('Ab jetzt können :count Wahlberechtigte abstimmen. Kandidaturen und Zählregel sind danach fest. Schließt ihn niemand, nimmt er nach :minutes Minuten keine Stimmen mehr an.', [
                'count' => $round->parent_round_id !== null
                    ? ElectionRollEntry::query()->where('election_round_id', $round->parent_round_id)->count()
                    : app(ElectoralRoll::class)->entitled($round->election, now())->count(),
                'minutes' => \App\Services\Election\OpenRound::LIVE_MAX_MINUTES,
            ]),
        };
    }

    /**
     * How many of the roll have voted so far — read before the board closes
     * a round, so nobody closes at 0 of 14 by mistake. Counts only.
     */
    protected function turnoutText(ElectionRound $round): string
    {
        $cast = $round->votes()->count();
        $roll = $round->rollEntries()->count();

        return $cast === 0
            ? __('Noch hat niemand abgestimmt: 0 von :roll Wahlberechtigten.', ['roll' => $roll])
            : __('Abgestimmt haben bisher :cast von :roll Wahlberechtigten.', ['cast' => $cast, 'roll' => $roll]);
    }

    /**
     * The round of the same election that takes ballots right now, other
     * than `$round` — only one is open at a time.
     */
    protected function openRoundBesides(ElectionRound $round): ?ElectionRound
    {
        $now = now();

        return ElectionRound::query()
            ->where('election_event_id', $round->election_event_id)
            ->whereKeyNot($round->getKey())
            ->whereNotNull('opened_at')
            ->get()
            ->first(fn (ElectionRound $other): bool => $other->stateAt($now) === ElectionRoundState::Open);
    }

    /**
     * A sealed round of the election whose tie nobody has decided yet.
     */
    protected function tieRound(int $roundId): ?ElectionRound
    {
        $round = $this->round($roundId);

        abort_unless($round->isSealed(), 404);

        // A stored vote that does not fit the round: no count, so no dialog — the board learns why instead of a 500.
        try {
            $outcome = app(TallyRound::class)->effective($round)->outcome;
        } catch (\UnexpectedValueException) {
            Flux::toast(app(RefusalText::class)->unreadable($round), variant: 'danger');

            return null;
        }

        abort_unless($outcome === ElectionOutcome::Tie, 404);

        return $round;
    }

    /**
     * The tied candidates of the round in the tie dialogs, with their counts.
     *
     * @return array{round: ElectionRound, kind: ElectionTieKind, seats: int, candidates: list<array{id: int, name: string, for: int, yes: int, no: int}>}|null
     */
    #[Computed]
    public function tie(): ?array
    {
        $this->boardActor();

        $round = $this->tieRoundId === null ? null : ElectionRound::query()->where('election_event_id', $this->electionId)->with('candidates.pleb.profile')->find($this->tieRoundId);

        if ($round === null || ! $round->isSealed()) {
            return null;
        }

        try {
            $result = app(TallyRound::class)->handle($round);
        } catch (\UnexpectedValueException) {
            return null;
        }

        if ($result->tieKind === null) {
            return null;
        }

        return [
            'round' => $round,
            'kind' => $result->tieKind,
            'seats' => $result->tiedSeats,
            'candidates' => array_map(fn (int $id): array => [
                'id' => $id,
                'name' => MemberProposal::authorNameOf($round->candidates->firstWhere('id', $id)?->pleb),
                'pubkey' => $round->candidates->firstWhere('id', $id)?->pleb?->pubkey,
                ...$result->counts[$id],
            ], $result->tied),
        ];
    }

    protected function canRepeat(ElectionRound $round): bool
    {
        return app(ElectionStewardship::class)->awaitsRepeat($round);
    }

    /**
     * A round of this election, or 404.
     */
    protected function round(int $roundId): ElectionRound
    {
        return ElectionRound::query()->where('election_event_id', $this->electionId)->findOrFail($roundId);
    }

    /**
     * A step taken from another tab brings the board to the round list.
     */
    protected function showLive(): void
    {
        if (! $this->visible) {
            $this->dispatch('show-section', section: 'live');
        }
    }

    protected function acted(): void
    {
        unset($this->election, $this->roundReports);
        $this->dispatch('election-changed');
    }
};
?>

<div>
    @if ($visible)
    @php
        $election = $this->election;
        $reports = $this->roundReports;
        $titles = $reports->mapWithKeys(fn ($report) => [$report['round']->id => $report['round']->title]);
        // Only one round takes ballots at a time: while one is open, no other is opened.
        $openReport = $reports->first(fn ($report) => $report['state'] === \App\Enums\ElectionRoundState::Open);
        // Numbering follows the agenda, as on the share view: a run-off belongs to its round, an annulled round is void and its repeat takes its number.
        $number = 0;
        // A name may end in a full stop ("Erik E."); the sentence must not add a second one.
        $sentence = fn (string $text): string => preg_replace('/(?<=\S)\.\.(?=\s|$)/u', '.', $text);
    @endphp

    <div class="relative" data-test="live">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <p class="max-w-2xl text-base text-text-secondary">
                {{ __('Hier steuert ihr die Wahlgänge in der Sitzung. Teilt im Call die Ansicht zum Teilen: Sie zeigt Beteiligung und Ergebnis groß, ohne Bedienelemente, und aktualisiert sich von selbst.') }}
            </p>
            <div class="flex flex-col gap-2 sm:flex-row lg:shrink-0">
                <flux:button :href="route('association.election.admin.screen', $election)" target="_blank" icon="tv" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="open-screen">
                    {{ __('Ansicht zum Teilen') }}
                </flux:button>
                <flux:button :href="route('association.election.admin.protocol', $election)" target="_blank" icon="document-text" variant="ghost" class="min-h-11 w-full sm:w-auto" data-test="open-protocol">
                    {{ __('Beschlussprotokoll') }}
                </flux:button>
            </div>
        </div>

        @if ($reports->isEmpty())
            <p class="mt-8 max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10 text-base text-text-secondary">{{ __('Diese Wahl hat noch keinen Wahlgang. Legt ihn unter „Einstellungen“ an.') }}</p>
        @else
            <ol class="mt-8 border-b border-border-default" data-test="live-rounds">
                @foreach ($reports as $report)
                    @php
                        $round = $report['round'];
                        $isRunOff = $round->parent_round_id !== null;
                        $isVoid = ! $isRunOff && $report['state'] === \App\Enums\ElectionRoundState::Annulled;
                        if (! $isRunOff && ! $isVoid) { $number++; }
                        $isOpen = $report['state'] === \App\Enums\ElectionRoundState::Open;
                        $result = $report['result'];
                        $isYesNo = $round->counting_rule === \App\Enums\ElectionCountingRule::YesNoPerCandidate;
                    @endphp
                    <li wire:key="live-round-{{ $round->id }}" @class([
                            'relative grid grid-cols-[2rem_minmax(0,1fr)] gap-x-3 border-t border-border-default py-6 sm:grid-cols-[2.5rem_minmax(0,1fr)]',
                            'sm:pl-8' => $isRunOff,
                        ]) data-test="live-round-{{ $round->id }}" data-state="{{ $report['over_by_clock'] ? 'over' : $report['state']->value }}">
                        {{-- The "now" marker: the open round carries the same orange rule as the next step. --}}
                        @if ($isOpen)
                            <span class="absolute -left-3 top-6 bottom-6 w-1 bg-orange-500 sm:-left-4" aria-hidden="true"></span>
                        @endif
                        <span class="pt-0.5 text-lg font-bold text-text-tertiary tabular-nums" aria-hidden="true" data-test="live-number">{{ $isRunOff || $isVoid ? '' : $number }}</span>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                                <h3 class="min-w-0 text-xl font-bold leading-tight text-text-primary break-words">
                                    <span class="sr-only">{{ match (true) { $isRunOff => __('Stichwahl'), $isVoid => __('Annullierter Wahlgang, zählt nicht mit'), default => __('Wahlgang :number', ['number' => $number]) } }}:</span>{{ $round->title }}
                                </h3>
                                <p class="inline-flex items-center gap-1.5 text-base" data-test="live-state">
                                    @if ($isOpen)
                                        <span class="block size-2.5 rounded-full bg-orange-500" aria-hidden="true"></span>
                                        <span class="font-bold text-text-primary">{{ __('Offen seit :time Uhr', ['time' => $this->clock($round->opened_at)]) }}</span>
                                    @elseif ($report['over_by_clock'])
                                        <flux:icon.clock variant="mini" class="size-5 text-warning" aria-hidden="true" />
                                        <span class="text-warning">{{ __('Zeit abgelaufen') }}</span>
                                    @elseif ($report['state'] === \App\Enums\ElectionRoundState::Annulled)
                                        <flux:icon.no-symbol variant="mini" class="size-5 text-danger" aria-hidden="true" />
                                        <span class="text-danger">{{ __('Annulliert um :time Uhr', ['time' => $this->clock($round->annulled_at)]) }}</span>
                                    @elseif ($report['sealed'])
                                        <flux:icon.check-circle variant="mini" class="size-5 text-success" aria-hidden="true" />
                                        <span class="text-text-primary">{{ __('Ausgezählt um :time Uhr', ['time' => $this->clock($round->closed_at)]) }}</span>
                                    @else
                                        <flux:icon.pause-circle variant="mini" class="size-5 text-text-tertiary" aria-hidden="true" />
                                        <span class="text-text-secondary">{{ __('Noch nicht geöffnet') }}</span>
                                    @endif
                                </p>
                            </div>
                            <p class="mt-1 text-sm text-text-secondary break-words">
                                @if ($isRunOff)
                                    @if ($isYesNo)
                                        {{ trans_choice('{1} Stichwahl zu „:parent“, ein Sitz. Abgestimmt wird mit Ja oder Nein.|[2,*] Stichwahl zu „:parent“, :count Sitze. Abgestimmt wird mit Ja oder Nein.', $round->seats, ['parent' => $titles->get($round->parent_round_id, ''), 'count' => $round->seats]) }}
                                    @else
                                        {{ trans_choice('{1} Stichwahl zu „:parent“, ein Sitz. Gewählt ist, wer die meisten Stimmen hat.|[2,*] Stichwahl zu „:parent“, :count Sitze. Gewählt sind, die am häufigsten genannt werden.', $round->seats, ['parent' => $titles->get($round->parent_round_id, ''), 'count' => $round->seats]) }}
                                    @endif
                                @else
                                    {{ $round->counting_rule->label() }}, {{ trans_choice('{1} ein Sitz|[2,*] :count Sitze', $round->seats, ['count' => $round->seats]) }}.
                                @endif
                                @if (! $report['sealed'] && $report['candidates'] !== [])
                                    {{ __('Zur Wahl: :names.', ['names' => implode(', ', $report['candidates'])]) }}
                                @endif
                            </p>

                            @if ($isOpen)
                                <div class="mt-5">
                                    <livewire:association.election.turnout :round-id="$round->id" :key="'turnout-'.$round->id" />
                                </div>
                                <p class="mt-3 text-sm text-text-secondary">{{ __('Nimmt bis :time Uhr Stimmen an, wenn ihr ihn nicht vorher schließt.', ['time' => $this->clock($round->closes_at)]) }}</p>
                                <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                                    <flux:button wire:click="startRoundAction({{ $round->id }}, 'close')" icon="stop-circle" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="close-round-{{ $round->id }}">{{ __('Wahlgang schließen') }}</flux:button>
                                    <flux:button wire:click="startRoundAction({{ $round->id }}, 'annul')" variant="ghost" class="min-h-11 w-full text-danger! sm:w-auto" data-test="annul-round-{{ $round->id }}">{{ __('Annullieren') }}</flux:button>
                                </div>
                            @elseif ($report['over_by_clock'])
                                <p class="mt-4 text-base text-text-primary">{{ trans_choice('{1} :count von :roll hat abgestimmt.|[0,*] :count von :roll haben abgestimmt.', $report['cast'], ['roll' => $report['roll']]) }} {{ __('Nach :minutes Minuten nimmt der Wahlgang keine Stimmen mehr an.', ['minutes' => \App\Services\Election\OpenRound::LIVE_MAX_MINUTES]) }}</p>
                                <div class="mt-4">
                                    <flux:button wire:click="sealRound({{ $round->id }})" icon="calculator" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="seal-round-{{ $round->id }}">{{ __('Auszählen') }}</flux:button>
                                </div>
                            @elseif (($report['unreadable'] ?? false) && $report['sealed'])
                                {{-- The stored votes do not fit the round: no result, no run-off, only the way to check it. --}}
                                <p class="mt-4 flex items-start gap-2 text-base font-bold text-danger break-words" data-test="live-unreadable-{{ $round->id }}">
                                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                                    <span>{{ __('Ergebnis nicht lesbar: Die gespeicherten Stimmen passen nicht zu diesem Wahlgang. Prüfen mit „php artisan election:verify :round“.', ['round' => $round->id]) }}</span>
                                </p>
                                <div class="mt-5">
                                    <flux:button wire:click="startRoundAction({{ $round->id }}, 'annul')" variant="ghost" class="min-h-11 w-full text-danger! sm:w-auto" data-test="annul-round-{{ $round->id }}">{{ __('Wahlgang annullieren') }}</flux:button>
                                </div>
                            @elseif ($result !== null)
                                @php $electedNames = collect($result['rows'])->where('elected', true)->pluck('name'); @endphp
                                <p class="mt-4 text-lg font-bold text-text-primary break-words" data-test="live-outcome-{{ $round->id }}">
                                    @switch($result['outcome'])
                                        @case(\App\Enums\ElectionOutcome::Complete)
                                            {{ $sentence(trans_choice('{1} Gewählt ist :names.|[2,*] Gewählt sind :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}
                                            @break
                                        @case(\App\Enums\ElectionOutcome::Tie)
                                            <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.scale variant="mini" class="size-5" aria-hidden="true" />{{ $result['tie_kind'] === \App\Enums\ElectionTieKind::YesEqualsNo ? $sentence(\App\Support\ElectionTieText::yesEqualsNo(collect($result['rows'])->where('tied', true)->pluck('name')->all())) : trans_choice('{1} Gleichstand um einen Sitz.|[2,*] Gleichstand um :count Sitze.', $result['tied_seats'], ['count' => $result['tied_seats']]) }}</span>
                                            <span class="block text-base font-normal text-text-primary" data-test="live-tie-next-{{ $round->id }}">{{ $report['run_off_resolved'] ? \App\Support\ElectionTieText::runOffResolved() : \App\Support\ElectionTieText::pending() }}</span>
                                            @if ($electedNames->isNotEmpty())
                                                <span class="block text-base font-normal text-text-primary">{{ $sentence(trans_choice('{1} Gewählt ist schon :names.|[2,*] Gewählt sind schon :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}</span>
                                            @endif
                                            @break
                                        @default
                                            <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="mini" class="size-5" aria-hidden="true" />{{ $electedNames->isEmpty() ? __('Niemand hat die nötige Mehrheit.') : __('Nicht alle Sitze sind besetzt.') }}</span>
                                            @if ($electedNames->isNotEmpty())
                                                <span class="block text-base font-normal text-text-primary">{{ $sentence(trans_choice('{1} Gewählt ist :names.|[2,*] Gewählt sind :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}</span>
                                            @endif
                                            {{-- No run-off follows (one candidate, or a yes/no round): say that the seat stays empty and how to vote again. --}}
                                            @if ($report['run_off'] === null && ! $report['has_run_off'] && ($freeSeats = $round->seats - $electedNames->count()) > 0)
                                                <span class="block text-base font-normal text-text-primary" data-test="live-seats-open-{{ $round->id }}">{{ trans_choice('{1} Der Sitz bleibt frei.|[2,*] :count Sitze bleiben frei.', $freeSeats, ['count' => $freeSeats]) }} {{ __('Neu abstimmen geht nur über „Wahlgang annullieren“ und danach „Wahlgang wiederholen“.') }}</span>
                                            @endif
                                    @endswitch
                                    @if ($result['casting_vote'] !== null)
                                        <span class="mt-1 block text-base font-normal text-text-primary" data-test="live-casting-vote-{{ $round->id }}">{{ $sentence(\App\Support\ElectionTieText::decided($result['tie_kind'], $result['casting_vote']['chair'], $result['casting_vote']['elected'], $result['casting_vote']['tied'])) }}@if ($result['casting_vote']['note']) {{ __('Anmerkung: :note', ['note' => $result['casting_vote']['note']]) }}@endif</span>
                                    @endif
                                </p>

                                <ul class="mt-4 max-w-2xl divide-y divide-border-subtle border-y border-border-subtle" data-test="live-result-{{ $round->id }}">
                                    @foreach ($result['rows'] as $row)
                                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 gap-y-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto_8rem]">
                                            <span class="min-w-0 font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$report['round']->candidates->firstWhere('id', $row['candidate_id'])?->pleb?->pubkey" :picture="$report['round']->candidates->firstWhere('id', $row['candidate_id'])?->pleb?->profile?->picture" :name="$row['name']" /></span>
                                            <span class="text-right tabular-nums text-text-primary">
                                                @if ($isYesNo)
                                                    {{ __(':yes Ja, :no Nein', ['yes' => $row['yes'], 'no' => $row['no']]) }}
                                                @else
                                                    {{ trans_choice('{0} 0 Stimmen|{1} 1 Stimme|[2,*] :count Stimmen', $row['for'], ['count' => $row['for']]) }}
                                                @endif
                                            </span>
                                            <span class="col-span-2 text-sm sm:col-span-1 sm:text-right">
                                                @if ($row['elected'])
                                                    <span class="inline-flex items-center gap-1 text-success"><flux:icon.check-circle variant="micro" class="size-4" aria-hidden="true" />{{ __('gewählt') }}</span>
                                                @elseif ($row['tied'])
                                                    <span class="inline-flex items-center gap-1 text-warning"><flux:icon.scale variant="micro" class="size-4" aria-hidden="true" />{{ __('Gleichstand') }}</span>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-3 text-sm text-text-secondary break-words">
                                    {{ trans_choice('{1} :count von :roll hat abgestimmt,|[0,*] :count von :roll haben abgestimmt,', $report['cast'], ['roll' => $report['roll']]) }} {{ trans_choice('{0} davon keine Enthaltung (leer).|{1} davon 1 Enthaltung (leer).|[2,*] davon :count Enthaltungen (leer).', $result['blank']) }}
                                    @if ($report['reason'])
                                        {{ __('Geschlossen mit dem Grund „:reason“.', ['reason' => $report['reason']]) }}
                                    @endif
                                </p>
                                @if ($report['manifest'])
                                    <p class="mt-1 text-sm text-text-tertiary break-all">
                                        {{ __('Prüfsumme :hash', ['hash' => $report['manifest']['hash']]) }}
                                        @unless ($report['manifest']['intact'])
                                            <span class="inline-flex items-center gap-1 text-danger"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('stimmt nicht mehr mit den Stimmen überein') }}</span>
                                        @endunless
                                    </p>
                                @endif

                                <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                    {{-- A tie: the chair's casting vote is the statutory way (Art. 6); a run-off only if the assembly resolves one. Both open a dialog. --}}
                                    @if ($result['outcome'] === \App\Enums\ElectionOutcome::Tie && ! $report['has_run_off'])
                                        <flux:button wire:click="startCastingVote({{ $round->id }})" icon="scale" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="casting-vote-{{ $round->id }}">{{ __('Stichentscheid eintragen') }}</flux:button>
                                        <flux:button wire:click="startTieRunOff({{ $round->id }})" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="tie-run-off-{{ $round->id }}">{{ __('Stichwahl anlegen') }}</flux:button>
                                    {{-- The run-off of a missed majority is set up from the next-step card, the one place it is offered. --}}
                                    @elseif ($report['run_off'] && ! $report['has_run_off'])
                                        <p class="min-w-0 text-sm text-text-secondary break-words sm:mr-4">{{ trans_choice('{1} Zwischen :names, um einen Sitz.|[2,*] Zwischen :names, um :count Sitze.', $report['run_off']['seats'], ['names' => implode(', ', $report['run_off']['candidates']), 'count' => $report['run_off']['seats']]) }}</p>
                                    @endif
                                    <flux:button wire:click="startRoundAction({{ $round->id }}, 'annul')" variant="ghost" class="min-h-11 w-full text-danger! sm:w-auto" data-test="annul-round-{{ $round->id }}">{{ __('Wahlgang annullieren') }}</flux:button>
                                </div>
                            @elseif ($report['state'] === \App\Enums\ElectionRoundState::Annulled)
                                <p class="mt-4 text-base text-text-primary break-words">
                                    @if ($round->opened_at === null)
                                        {{ __('Nicht geöffnet, annulliert.') }}
                                    @elseif ($round->closed_at !== null)
                                        {{-- Counted before it was annulled: the minutes keep the result, marked as void. --}}
                                        {{ trans_choice('{0} Ausgezählt ohne Stimme, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.|{1} 1 Stimme ausgezählt, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.|[2,*] :count Stimmen ausgezählt, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.', $report['cast']) }}
                                    @else
                                        {{ trans_choice('{0} Keine Stimme abgegeben, nicht ausgezählt.|{1} 1 Stimme abgegeben, nicht ausgezählt.|[2,*] :count Stimmen abgegeben, nicht ausgezählt.', $report['cast']) }}
                                    @endif
                                    @if ($report['reason'])
                                        {{ __('Grund: :reason', ['reason' => $report['reason']]) }}
                                    @endif
                                </p>
                                @if ($this->canRepeat($round))
                                    <div class="mt-5">
                                        <flux:button wire:key="repeat-round-{{ $round->id }}" wire:click="startRoundAction({{ $round->id }}, 'repeat')" icon="arrow-path" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="repeat-round-{{ $round->id }}">{{ __('Wahlgang wiederholen') }}</flux:button>
                                    </div>
                                @endif
                            @elseif ($openReport !== null)
                                <p class="mt-4 text-sm text-text-secondary" data-test="open-round-waits-{{ $round->id }}">
                                    {{ __('Öffnen geht, sobald „:round“ geschlossen ist.', ['round' => $openReport['round']->title]) }}
                                </p>
                            @else
                                <div class="mt-5">
                                    <flux:button wire:key="open-round-{{ $round->id }}" wire:click="startRoundAction({{ $round->id }}, 'open')" icon="play" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="open-round-{{ $round->id }}">{{ __('Wahlgang öffnen') }}</flux:button>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($election->is_test)
            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="live-purge">
                <p class="inline-flex items-start gap-2 text-base text-text-secondary">
                    <flux:icon.beaker variant="mini" class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                    {{ __('Das ist eine Probewahl. Nach der Probe löscht ihr sie mit allen Stimmen und Protokollzeilen.') }}
                </p>
                <flux:modal.trigger name="purge-election">
                    <flux:button variant="ghost" icon="trash" class="min-h-11 w-full shrink-0 text-danger! sm:w-auto" data-test="live-purge-election">{{ __('Probewahl löschen') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </div>
    @endif

    @php
        $pendingRound = $pendingRoundId ? \App\Models\ElectionRound::query()->with('election')->find($pendingRoundId) : null;
    @endphp
    <flux:modal name="round-action" class="w-full max-w-lg">
        <form wire:submit="confirmRoundAction" class="space-y-6" data-test="round-action-form">
            <div>
                <flux:heading size="lg">
                    @if ($pendingRound)
                        {{ match ($pendingRoundAction) {
                            'close' => __('„:round“ schließen?', ['round' => $pendingRound->title]),
                            'annul' => __('„:round“ annullieren?', ['round' => $pendingRound->title]),
                            'strike' => __('„:round“ streichen?', ['round' => $pendingRound->title]),
                            'repeat' => __('„:round“ wiederholen?', ['round' => $pendingRound->title]),
                            default => __('„:round“ öffnen?', ['round' => $pendingRound->title]),
                        } }}
                    @endif
                </flux:heading>
                <flux:text class="mt-2">
                    @if ($pendingRound)
                        {{ $this->pendingRoundText($pendingRound) }}
                    @endif
                </flux:text>
            </div>
            @if (in_array($pendingRoundAction, ['close', 'annul', 'strike'], true))
                <flux:field>
                    <flux:label>{{ __('Grund für das Protokoll') }}</flux:label>
                    <flux:textarea wire:model="roundReason" rows="2" maxlength="1000" data-test="round-reason" autofocus
                                   placeholder="{{ match ($pendingRoundAction) { 'annul' => __('z. B. falsche Kandidatenliste geöffnet'), 'strike' => __('z. B. niemand kann mehr antreten'), default => __('z. B. alle Anwesenden haben abgestimmt') } }}" />
                    <flux:error name="roundReason" />
                </flux:field>
            @else
                <flux:error name="roundReason" />
            @endif
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto" :autofocus="! in_array($pendingRoundAction, ['close', 'annul', 'strike'], true)" data-test="cancel-round-action">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" :variant="in_array($pendingRoundAction, ['annul', 'strike'], true) ? 'danger' : 'primary'" class="min-h-11 w-full sm:w-auto" data-test="confirm-round-action">
                    {{ match ($pendingRoundAction) {
                        'close' => __('Schließen und auszählen'),
                        'annul' => __('Annullieren'),
                        'strike' => __('Wahlgang streichen'),
                        'repeat' => __('Wahlgang wiederholen'),
                        default => __('Wahlgang öffnen'),
                    } }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    @php $tie = $this->tie; @endphp
    {{-- The chair's casting vote on a tie (statutes Art. 6): the election officer enters it as the chair announced it. --}}
    <flux:modal name="casting-vote" class="w-full max-w-lg">
        <form wire:submit="recordCastingVote" class="space-y-6" data-test="casting-vote-form">
            <div>
                <flux:heading size="lg">{{ $tie ? __('Stichentscheid zu „:round“', ['round' => $tie['round']->title]) : __('Stichentscheid') }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($tie && $tie['kind'] === \App\Enums\ElectionTieKind::YesEqualsNo)
                        {{ trans_choice('{1} Bei gleich vielen Ja- wie Nein-Stimmen fällt die/der Vorsitzende den Stichentscheid. Hake an, wen sie oder er mit Ja bestätigt; wer nicht angehakt ist, ist mit Nein entschieden. Frei ist ein Sitz.|[2,*] Bei gleich vielen Ja- wie Nein-Stimmen fällt die/der Vorsitzende den Stichentscheid. Hake an, wen sie oder er mit Ja bestätigt; wer nicht angehakt ist, ist mit Nein entschieden. Frei sind :count Sitze.', $tie['seats'], ['count' => $tie['seats']]) }}
                    @elseif ($tie)
                        {{ trans_choice('{1} Bei Stimmengleichheit fällt die/der Vorsitzende den Stichentscheid. Hake die Person an, für die sie oder er entschieden hat.|[2,*] Bei Stimmengleichheit fällt die/der Vorsitzende den Stichentscheid. Hake die :count Personen an, für die sie oder er entschieden hat.', $tie['seats'], ['count' => $tie['seats']]) }}
                    @endif
                    {{ __('Der Eintrag steht im Protokoll und lässt sich nicht ändern.') }}
                </flux:text>
            </div>
            @if ($tie)
                <fieldset>
                    <legend class="text-sm font-medium text-text-primary">{{ __('Entschieden für') }}</legend>
                    {{-- Every point of a row ticks: the label covers the row from the box to the counts, and the box column above and below the 18 px box is the checkbox's own transparent ::before, stretched to the row's height. --}}
                    <flux:checkbox.group wire:model="castingCandidates">
                        <ul class="mt-2 divide-y divide-border-subtle border-y border-border-subtle">
                            @foreach ($tie['candidates'] as $candidate)
                                <li wire:key="casting-{{ $tie['round']->id }}-{{ $candidate['id'] }}">
                                    <flux:field variant="inline" class="relative items-center gap-x-0!">
                                        <flux:checkbox value="{{ $candidate['id'] }}" :autofocus="$loop->first" class="before:absolute before:inset-y-0 before:start-0 before:w-[1.125rem] before:cursor-pointer" data-test="casting-checkbox-{{ $candidate['id'] }}" />
                                        <flux:label class="flex! min-h-11 cursor-pointer items-center gap-3 py-2 ps-3 text-base!" data-test="casting-candidate-{{ $candidate['id'] }}">
                                            <span class="min-w-0 flex-1 font-bold break-words text-text-primary"><x-nostr-profile trigger="avatar" :pubkey="$candidate['pubkey']" :name="$candidate['name']" /></span>
                                            <span class="shrink-0 text-sm font-normal tabular-nums text-text-secondary">
                                                @if ($tie['kind'] === \App\Enums\ElectionTieKind::YesEqualsNo || $tie['round']->counting_rule === \App\Enums\ElectionCountingRule::YesNoPerCandidate)
                                                    {{ __(':yes Ja, :no Nein', ['yes' => $candidate['yes'], 'no' => $candidate['no']]) }}
                                                @else
                                                    {{ trans_choice('{0} 0 Stimmen|{1} 1 Stimme|[2,*] :count Stimmen', $candidate['for'], ['count' => $candidate['for']]) }}
                                                @endif
                                            </span>
                                        </flux:label>
                                    </flux:field>
                                </li>
                            @endforeach
                        </ul>
                    </flux:checkbox.group>
                    <flux:error name="castingCandidates" />
                    <flux:error name="castingCandidates.*" />
                    @if ($tie['kind'] === \App\Enums\ElectionTieKind::YesEqualsNo)
                        <flux:field variant="inline" class="relative mt-2 items-center gap-x-0!">
                            <flux:checkbox wire:model="castingAllNo" class="before:absolute before:inset-y-0 before:start-0 before:w-[1.125rem] before:cursor-pointer" data-test="casting-all-no" />
                            <flux:label class="flex! min-h-11 cursor-pointer items-center py-2 ps-3 text-base!">{{ __('Die/der Vorsitzende hat alle mit Nein entschieden') }}</flux:label>
                        </flux:field>
                    @endif
                </fieldset>
            @else
                <flux:error name="castingCandidates" />
            @endif
            <flux:field class="[&_input]:min-h-11">
                <flux:label>{{ __('Name der/des Vorsitzenden') }}</flux:label>
                <flux:input wire:model="castingChair" maxlength="{{ \App\Services\Election\RecordCastingVote::MAX_CHAIR_LENGTH }}" autocomplete="off" data-test="casting-chair" />
                <flux:error name="castingChair" />
            </flux:field>
            <flux:field>
                <flux:label badge="{{ __('optional') }}">{{ __('Anmerkung für das Protokoll') }}</flux:label>
                <flux:textarea wire:model="castingNote" rows="2" maxlength="{{ \App\Services\Election\ElectionAuditLog::MAX_REASON_LENGTH }}" data-test="casting-note" />
                <flux:error name="castingNote" />
            </flux:field>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto" data-test="cancel-casting-vote">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="confirm-casting-vote">{{ __('Stichentscheid eintragen') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- A run-off for a tie exists only by resolution of the assembly. --}}
    <flux:modal name="tie-run-off" class="w-full max-w-lg">
        <form wire:submit="confirmTieRunOff" class="space-y-6" data-test="tie-run-off-form">
            <div>
                <flux:heading size="lg">{{ $tie ? __('Stichwahl zu „:round“ anlegen?', ['round' => $tie['round']->title]) : __('Stichwahl anlegen?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Nach den Statuten fällt bei Stimmengleichheit die/der Vorsitzende den Stichentscheid. Eine Stichwahl gibt es nur, wenn die Versammlung sie beschlossen hat. Endet auch sie im Gleichstand, fällt die/der Vorsitzende den Stichentscheid.') }}
                </flux:text>
            </div>
            {{-- As in the casting-vote rows: the label and the checkbox's stretched ::before cover the whole row. The error sits below the field, outside the row the ::before spans. --}}
            <div>
                <flux:field variant="inline" class="relative items-center gap-x-0!" data-test="run-off-row">
                    <flux:checkbox wire:model="runOffResolved" autofocus class="before:absolute before:inset-y-0 before:start-0 before:w-[1.125rem] before:cursor-pointer" data-test="run-off-resolved" />
                    <flux:label class="flex! min-h-11 cursor-pointer items-center py-2 ps-3 text-base! break-words" data-test="run-off-resolved-label">{{ __('Die Versammlung hat eine Stichwahl beschlossen.') }}</flux:label>
                </flux:field>
                <flux:error name="runOffResolved" />
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto" data-test="cancel-tie-run-off">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="confirm-tie-run-off">{{ __('Stichwahl anlegen') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
