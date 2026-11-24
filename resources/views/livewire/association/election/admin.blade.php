<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionPhase;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Livewire\Traits\WithViewer;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\EnrolByHand;
use App\Services\Election\PurgeTestElection;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\RefusalText;
use App\Services\Election\RoundReport;
use App\Services\Election\RunSheet;
use App\Services\Election\TallyRound;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The board runs the election here: a run sheet per election with the one
 * next step, the settings of an election and its rounds, the candidacies and
 * the electoral roll.
 *
 * The board is the Wahlleitung — every current board member may take every
 * step, also as a candidate (user, 2026-11-14). Every public method resolves
 * the acting board member from the session and answers 403 to anybody else;
 * the services ask `ElectionPolicy` once more at the moment of the act.
 *
 * Counts only: nothing on this page reads a vote.
 */
new class extends Component
{
    use WithViewer;

    private const SECTIONS = ['settings', 'candidacies', 'roll', 'live'];

    private const DEFAULT_CLOSE_REASON = 'Abstimmung in der Sitzung beendet.';

    #[Locked]
    public bool $isGuest = false;

    #[Locked]
    public ?int $selectedElectionId = null;

    #[Locked]
    public bool $creating = false;

    public string $section = 'settings';

    public ?int $rollRoundId = null;

    public ?int $enrolMemberId = null;

    public string $enrolReason = '';

    public ?int $entitleMemberId = null;

    public string $entitleReason = '';

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

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->boardActor();

        $first = $this->elections->first();

        if ($first !== null) {
            $this->select($first);
        }
    }

    public function startCreate(): void
    {
        $this->boardActor();

        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->boardActor();

        $this->creating = false;
    }

    /**
     * The settings form stored a new draft: show it.
     */
    #[On('election-created')]
    public function electionCreated(int $electionId): void
    {
        $this->boardActor();

        $this->creating = false;
        unset($this->elections);
        $this->select(Election::query()->findOrFail($electionId));
    }

    public function selectElection(int $electionId): void
    {
        $this->boardActor();

        $this->creating = false;
        $this->select(Election::query()->findOrFail($electionId));
    }

    public function showSection(string $section): void
    {
        $this->boardActor();

        $this->section = in_array($section, self::SECTIONS, true) ? $section : 'settings';
    }

    public function announce(ElectionStewardship $stewardship): void
    {
        $election = $stewardship->announce($this->requireSelected(), $this->boardActor());

        Flux::modal('announce-election')->close();
        $this->refreshElection($election);
        Flux::toast(__('„:name“ ist angekündigt.', ['name' => $election->name]), variant: 'success');
    }

    public function deleteElection(ElectionStewardship $stewardship): void
    {
        $election = $this->requireSelected();
        $stewardship->delete($election, $this->boardActor());

        Flux::modal('delete-election')->close();
        $this->selectedElectionId = null;
        unset($this->elections, $this->selectedElection);

        $next = $this->elections->first();

        if ($next !== null) {
            $this->select($next);
        }

        Flux::toast(__('„:name“ ist gelöscht.', ['name' => $election->name]));
    }

    public function enrol(EnrolByHand $enrolByHand): void
    {
        $actor = $this->boardActor();

        $this->validate(
            [
                'enrolMemberId' => ['required', 'integer'],
                'enrolReason' => ['required', 'string', 'min:3', 'max:1000'],
            ],
            [
                'enrolMemberId.required' => __('Wähle das Mitglied, das ihr nachtragt.'),
                'enrolReason.required' => __('Nenne einen Grund, etwa „Beitrag bar bezahlt“. Er steht im Protokoll der Wahl.'),
                'enrolReason.min' => __('Nenne einen Grund mit mindestens drei Zeichen.'),
                'enrolReason.max' => __('Der Grund darf höchstens 1000 Zeichen lang sein.'),
            ],
        );

        $round = $this->rollRound ?? abort(404);
        $member = EinundzwanzigPleb::query()->with('profile')->findOrFail($this->enrolMemberId);

        try {
            $enrolByHand->handle($round, $member, $actor, trim($this->enrolReason));
        } catch (ElectionRoundStateException) {
            $this->addError('enrolMemberId', __('Nachtragen geht nur, solange der Wahlgang offen ist.'));

            return;
        } catch (ElectionActionRefusedException) {
            $this->addError('enrolMemberId', __(':name steht schon im Verzeichnis oder ist weder Aktiv- noch Ehrenmitglied.', ['name' => MemberProposal::authorNameOf($member)]));

            return;
        }

        $this->enrolMemberId = null;
        $this->enrolReason = '';
        unset($this->rollEntries, $this->enrolCandidates, $this->elections, $this->selectedElection);
        Flux::toast(__(':name ist im Verzeichnis von „:round“ nachgetragen.', ['name' => MemberProposal::authorNameOf($member), 'round' => $round->title]), variant: 'success');
    }

    /**
     * Record that a member is entitled to vote in the selected election
     * although the portal knows no qualifying fee — paid in cash, say. From
     * then on the candidacy can be approved, and every round opened later
     * puts the member on its roll.
     */
    public function entitle(EnrolByHand $enrolByHand): void
    {
        $actor = $this->boardActor();

        $this->validate(
            [
                'entitleMemberId' => ['required', 'integer'],
                'entitleReason' => ['required', 'string', 'min:3', 'max:1000'],
            ],
            [
                'entitleMemberId.required' => __('Wähle das Mitglied, das ihr als wahlberechtigt eintragt.'),
                'entitleReason.required' => __('Nenne einen Grund, etwa „Beitrag bar bezahlt“. Er steht im Protokoll der Wahl.'),
                'entitleReason.min' => __('Nenne einen Grund mit mindestens drei Zeichen.'),
                'entitleReason.max' => __('Der Grund darf höchstens 1000 Zeichen lang sein.'),
            ],
        );

        $election = $this->requireSelected();
        $member = EinundzwanzigPleb::query()->with('profile')->findOrFail($this->entitleMemberId);
        $name = MemberProposal::authorNameOf($member);

        try {
            $enrolByHand->entitle($election, $member, $actor, trim($this->entitleReason));
        } catch (ElectionRoundStateException) {
            $this->addError('entitleMemberId', __('Jeder Wahlgang dieser Wahl ist schon geöffnet oder abgeschlossen. Tragt :name im Verzeichnis des offenen Wahlgangs nach.', ['name' => $name]));

            return;
        } catch (ElectionActionRefusedException $refusal) {
            $this->addError('entitleMemberId', str_contains($refusal->getMessage(), 'entitled already')
                ? __(':name ist schon wahlberechtigt. Es gibt nichts einzutragen.', ['name' => $name])
                : __(':name ist weder Aktiv- noch Ehrenmitglied. Wahlberechtigt sind nur Aktiv- und Ehrenmitglieder.', ['name' => $name]));

            return;
        }

        $this->entitleMemberId = null;
        $this->entitleReason = '';
        unset($this->rollPreview, $this->entitleCandidates, $this->elections, $this->selectedElection);
        Flux::toast(__(':name ist für „:election“ als wahlberechtigt eingetragen.', ['name' => $name, 'election' => $election->name]), variant: 'success');
    }

    /**
     * Ask before a round is opened, ended, annulled or repeated. Ending and
     * annulling take a reason, which goes into the audit log.
     */
    public function startRoundAction(int $roundId, string $kind): void
    {
        $this->boardActor();

        $round = ElectionRound::query()->findOrFail($roundId);

        if ((int) $round->election_event_id !== $this->selectedElectionId) {
            $this->select($round->election);
        }

        $this->pendingRoundId = (int) $round->id;
        $this->pendingRoundAction = in_array($kind, ['open', 'close', 'annul', 'strike', 'repeat'], true) ? $kind : 'open';
        $this->roundReason = $this->pendingRoundAction === 'close' ? __(self::DEFAULT_CLOSE_REASON) : '';
        $this->section = 'live';
        $this->resetErrorBag('roundReason');

        Flux::modal('round-action')->show();
    }

    public function confirmRoundAction(ElectionStewardship $stewardship): void
    {
        $actor = $this->boardActor();

        $round = ElectionRound::query()->findOrFail($this->pendingRoundId ?? abort(404));
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
            $this->addError('roundReason', app(RefusalText::class)->of($action, $exception, $this->selectedElection));

            return;
        } catch (ElectionActionRefusedException) {
            $this->addError('roundReason', __('„:round“ ist schon wiederholt oder nicht annulliert. Lade die Seite neu, vielleicht hat ihn schon jemand wiederholt.', ['round' => $round->title]));

            return;
        }

        Flux::modal('round-action')->close();
        $this->pendingRoundId = null;
        $this->pendingRoundAction = null;
        $this->roundReason = '';
        $this->refreshElection($round->election);

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
    public function sealRound(int $roundId, ElectionStewardship $stewardship): void
    {
        $actor = $this->boardActor();
        $round = ElectionRound::query()->findOrFail($roundId);

        try {
            $stewardship->close($round, $actor);
        } catch (ElectionRoundStateException) {
            Flux::toast(__('„:round“ nimmt noch Stimmen an. Schließt ihn über „Wahlgang schließen“.', ['round' => $round->title]), variant: 'danger');

            return;
        }

        $this->section = 'live';
        $this->refreshElection($round->election);
        Flux::toast(__('„:round“ ist ausgezählt.', ['round' => $round->title]), variant: 'success');
    }

    /**
     * Open the dialog in which the election officer enters the chair's
     * casting vote on the tie of a sealed round (statutes Art. 6).
     */
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
        $this->section = 'live';
        $this->resetErrorBag(['castingCandidates', 'castingChair', 'castingNote']);

        Flux::modal('casting-vote')->show();
    }

    public function recordCastingVote(RecordCastingVote $castingVotes, TallyRound $tally): void
    {
        $actor = $this->boardActor();
        $round = ElectionRound::query()->findOrFail($this->tieRoundId ?? abort(404));

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
            $this->addError('castingCandidates', app(RefusalText::class)->of('casting', $refusal, $this->selectedElection));

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
        $this->refreshElection($round->election);

        Flux::toast(__('Der Stichentscheid zu „:round“ ist eingetragen.', ['round' => $round->title]), variant: 'success');
    }

    /**
     * Open the dialog for the run-off of a tie: the statutes have the chair
     * decide, so a run-off only follows a resolution of the assembly.
     */
    public function startTieRunOff(int $roundId): void
    {
        $this->boardActor();
        $round = $this->tieRound($roundId);

        if ($round === null) {
            return;
        }

        $this->tieRoundId = (int) $round->id;
        $this->runOffResolved = false;
        $this->section = 'live';
        $this->resetErrorBag('runOffResolved');

        Flux::modal('tie-run-off')->show();
    }

    public function confirmTieRunOff(CreateRunOff $runOffs): void
    {
        $actor = $this->boardActor();
        $round = ElectionRound::query()->findOrFail($this->tieRoundId ?? abort(404));

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
        $this->refreshElection($round->election);

        Flux::toast(__('„:round“ ist angelegt. Öffnet sie, wenn alle bereit sind.', ['round' => $runOff->title]), variant: 'success');
    }

    public function createRunOff(int $roundId, CreateRunOff $runOffs): void
    {
        $actor = $this->boardActor();
        $round = ElectionRound::query()->findOrFail($roundId);

        try {
            // A tie needs the assembly's resolution, given in the dialog of startTieRunOff(); here it is refused.
            $runOff = $runOffs->handle($round, $actor);
        } catch (ElectionActionRefusedException|ElectionRoundStateException $exception) {
            Flux::toast(str_contains($exception->getMessage(), 'cannot be counted')
                ? app(RefusalText::class)->unreadableRunOff($round)
                : __('Für „:round“ gibt es keine Stichwahl anzulegen.', ['round' => $round->title]), variant: 'danger');

            return;
        }

        $this->section = 'live';
        $this->refreshElection($round->election);
        Flux::toast(__('„:round“ ist angelegt. Öffnet sie, wenn alle bereit sind.', ['round' => $runOff->title]), variant: 'success');
    }

    /**
     * Delete a test election with every row of it — rounds, votes, roll,
     * audit lines. Refused for a real election.
     */
    public function purgeTestElection(PurgeTestElection $purge): void
    {
        $this->boardActor();
        $election = $this->requireSelected();

        try {
            $purge->handle((int) $election->id);
        } catch (ElectionActionRefusedException) {
            Flux::toast(__('Nur eine Probewahl lässt sich vollständig löschen.'), variant: 'danger');

            return;
        }

        Flux::modal('purge-election')->close();
        $this->selectedElectionId = null;
        unset($this->elections, $this->selectedElection);

        $next = $this->elections->first();

        if ($next !== null) {
            $this->select($next);
        }

        Flux::toast(__('Die Probewahl „:name“ ist mit allen Stimmen und Protokollzeilen gelöscht.', ['name' => $election->name]));
    }

    /**
     * Another board member, or the turnout poll, saw a round change.
     */
    #[On('election-round-changed')]
    #[On('election-changed')]
    public function refreshRounds(): void
    {
        $this->boardActor();

        unset($this->elections, $this->selectedElection, $this->roundReports, $this->openedRounds, $this->rollRound);
    }

    /**
     * The rounds of the selected election with state, turnout and result.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function roundReports(): Collection
    {
        $this->boardActor();

        $election = $this->selectedElection;

        return $election === null ? collect() : app(RoundReport::class)->forElection($election);
    }

    public function updatedSection(): void
    {
        $this->boardActor();

        if (! in_array($this->section, self::SECTIONS, true)) {
            $this->section = 'settings';
        }
    }

    /**
     * Every election, newest first, with what the run sheet needs.
     *
     * @return EloquentCollection<int, Election>
     */
    #[Computed]
    public function elections(): EloquentCollection
    {
        $this->boardActor();

        return Election::query()
            ->with(['rounds' => fn ($rounds) => $rounds->with('candidates:id,election_round_id,status')->withCount('rollEntries')])
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function selectedElection(): ?Election
    {
        $this->boardActor();

        return $this->selectedElectionId === null
            ? null
            : $this->elections->firstWhere('id', $this->selectedElectionId);
    }

    /**
     * Before any round is opened: every active and honorary member, whether
     * the fee rule makes them entitled right now and why, and whether they
     * signed in since the announcement.
     *
     * @return Collection<int, array{name: string, reason: ElectionRollReason|null, signed_in: CarbonInterface|null}>
     */
    #[Computed]
    public function rollPreview(): Collection
    {
        $this->boardActor();

        $election = $this->selectedElection;

        if ($election === null) {
            return collect();
        }

        $roll = app(ElectoralRoll::class);
        $entitled = $roll->entitled($election, now())->get()->keyBy('id');

        return EinundzwanzigPleb::query()
            ->notErased()
            ->whereIn('association_status', array_column(ElectoralRoll::VOTING_STATUSES, 'value'))
            ->with('profile')
            ->get()
            ->map(fn (EinundzwanzigPleb $pleb): array => [
                'name' => MemberProposal::authorNameOf($pleb),
                'pubkey' => $pleb->pubkey,
                'picture' => $pleb->profile?->picture,
                'reason' => $entitled->has($pleb->id) ? $roll->reasonOf($entitled->get($pleb->id)) : null,
                'signed_in' => $this->signedInSinceAnnouncement($election, $pleb),
            ])
            ->sortBy(fn (array $row): string => ($row['reason'] === null ? '1' : '0').mb_strtolower($row['name']))
            ->values();
    }

    /**
     * The opened rounds of the selected election — each has a roll of its own.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function openedRounds(): Collection
    {
        $this->boardActor();

        return $this->selectedElection?->rounds->whereNotNull('opened_at')->values() ?? collect();
    }

    #[Computed]
    public function rollRound(): ?ElectionRound
    {
        $this->boardActor();

        return $this->openedRounds->firstWhere('id', $this->rollRoundId) ?? $this->openedRounds->last();
    }

    /**
     * @return Collection<int, array{name: string, reason: ElectionRollReason, source: ElectionRollSource, signed_in: CarbonInterface|null}>
     */
    #[Computed]
    public function rollEntries(): Collection
    {
        $this->boardActor();

        $round = $this->rollRound;
        $election = $this->selectedElection;

        if ($round === null || $election === null) {
            return collect();
        }

        return ElectionRollEntry::query()
            ->where('election_round_id', $round->id)
            ->with('pleb.profile')
            ->get()
            ->map(fn (ElectionRollEntry $entry): array => [
                'name' => MemberProposal::authorNameOf($entry->pleb),
                'pubkey' => $entry->pleb?->pubkey,
                'picture' => $entry->pleb?->profile?->picture,
                'reason' => $entry->reason,
                'source' => $entry->source,
                'signed_in' => $entry->pleb === null ? null : $this->signedInSinceAnnouncement($election, $entry->pleb),
            ])
            ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
            ->values();
    }

    /**
     * Active and honorary members not yet on the roll of the shown round.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function enrolCandidates(): Collection
    {
        $this->boardActor();

        $round = $this->rollRound;

        if ($round === null) {
            return collect();
        }

        return EinundzwanzigPleb::query()
            ->notErased()
            ->whereIn('association_status', [AssociationStatus::ACTIVE->value, AssociationStatus::HONORARY->value])
            ->whereNotIn('id', ElectionRollEntry::query()->select('einundzwanzig_pleb_id')->where('election_round_id', $round->id))
            ->with('profile')
            ->get()
            ->map(fn (EinundzwanzigPleb $pleb): array => ['id' => (int) $pleb->id, 'name' => MemberProposal::authorNameOf($pleb)])
            ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
            ->values();
    }

    /**
     * Active and honorary members the fee rule of the selected election does
     * not entitle right now — whom the board can enter as entitled by hand.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function entitleCandidates(): Collection
    {
        $this->boardActor();

        $election = $this->selectedElection;

        if ($election === null) {
            return collect();
        }

        return EinundzwanzigPleb::query()
            ->notErased()
            ->whereIn('association_status', array_column(ElectoralRoll::VOTING_STATUSES, 'value'))
            ->whereNotIn('id', app(ElectoralRoll::class)->entitled($election, now())->select('einundzwanzig_plebs.id'))
            ->with('profile')
            ->get()
            ->map(fn (EinundzwanzigPleb $pleb): array => ['id' => (int) $pleb->id, 'name' => MemberProposal::authorNameOf($pleb)])
            ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
            ->values();
    }

    /**
     * Can a manual entitlement still matter: is a round of the election
     * neither opened nor struck?
     */
    protected function takesEntitlement(Election $election): bool
    {
        return $election->rounds->contains(fn (ElectionRound $round): bool => $round->opened_at === null && $round->annulled_at === null);
    }

    /**
     * A stored moment in the zone the association meets in.
     */
    protected function local(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(config('app.display_timezone'));
    }

    protected function shortDate(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $this->local($moment)->format('d.m.Y');
    }

    protected function clock(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $this->local($moment)->format('H:i');
    }

    protected function longDate(CarbonInterface $moment): string
    {
        $local = $this->local($moment);

        return $local->locale('de')->isoFormat('dd, D. MMMM YYYY').', '.$local->format('H:i').' '.__('Uhr');
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
        $round = ElectionRound::query()->findOrFail($roundId);

        if ((int) $round->election_event_id !== $this->selectedElectionId) {
            $this->select($round->election);
        }

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

        $round = $this->tieRoundId === null ? null : ElectionRound::query()->with('candidates.pleb.profile')->find($this->tieRoundId);

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

    protected function signedInSinceAnnouncement(Election $election, EinundzwanzigPleb $pleb): ?CarbonInterface
    {
        return $election->announced_at !== null && $pleb->last_login_at !== null && $pleb->last_login_at->gte($election->announced_at)
            ? $pleb->last_login_at
            : null;
    }


    protected function requireSelected(): Election
    {
        return Election::query()->findOrFail($this->selectedElectionId ?? abort(404));
    }

    protected function select(Election $election): void
    {
        $this->resetErrorBag();
        $this->selectedElectionId = (int) $election->id;
        unset($this->selectedElection, $this->rollPreview, $this->openedRounds, $this->rollRound, $this->rollEntries, $this->enrolCandidates, $this->roundReports);
        $this->rollRoundId = null;
        $current = $this->selectedElection ?? $election;
        $step = app(RunSheet::class)->nextStepOf($current);
        $this->section = match (true) {
            $step['action'] === 'candidacies' => 'candidacies',
            $step['click'] !== null || $current->phase()->step() >= ElectionPhase::Meeting->step() => 'live',
            default => 'settings',
        };
    }

    protected function refreshElection(Election $election): void
    {
        unset($this->elections, $this->selectedElection, $this->rollPreview, $this->openedRounds, $this->rollRound, $this->rollEntries, $this->roundReports);
    }

};
?>

@php
    $election = $this->isGuest ? null : $this->selectedElection;
@endphp

<div class="election-ui relative w-full max-w-5xl mx-auto pb-20">
    @if ($isGuest)
        <div class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Melde dich mit Nostr an') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Die Wahlleitung ist dem Vorstand vorbehalten. Melde dich unten in der Navigation an.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @else
        <x-page-header>
            <x-slot:title>{{ __('Wahl leiten') }}</x-slot:title>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    {{ __('Der Vorstand bereitet hier die Wahl vor: Termin, Wahlgänge, Kandidaturen und das Verzeichnis der Wahlberechtigten. Gewählt wird live in der Sitzung.') }}
                </p>
            <x-slot:actions>
                @unless ($creating)
                    <flux:button wire:click="startCreate" icon="plus" class="min-h-11" :variant="$this->elections->isEmpty() ? 'primary' : 'outline'" data-test="start-create">
                        {{ __('Wahl anlegen') }}
                    </flux:button>
                @endunless
            </x-slot:actions>
        </x-page-header>

        @if ($creating)
            <section aria-labelledby="create-heading" class="mb-12 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-8" data-test="create-panel">
                <h2 id="create-heading" class="text-2xl font-bold text-text-primary">{{ __('Neue Wahl') }}</h2>
                <p class="mt-2 max-w-2xl text-base text-text-secondary">{{ __('Die Wahl bleibt ein Entwurf, bis ihr sie ankündigt. Mitglieder sehen sie erst danach.') }}</p>
                <div class="mt-8">
                    <livewire:association.election.admin-form key="create-election" />
                </div>
            </section>
        @endif

        @if ($this->elections->isEmpty() && ! $creating)
            <div class="max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10" data-test="empty-state">
                <p class="text-lg font-semibold text-text-primary">{{ __('Noch keine Wahl angelegt.') }}</p>
                <p class="mt-2 text-base text-text-secondary">{{ __('Legt die Wahl an, sobald der Sitzungstermin feststeht. Präsidium, Vorstand und Revisionsstelle sind als Wahlgänge vorbereitet.') }}</p>
            </div>
        @endif

        <div class="space-y-12">
            @foreach ($this->elections as $item)
                @php
                    $isSelected = $item->id === $selectedElectionId;
                    $step = app(RunSheet::class)->nextStepOf($item);
                @endphp
                <section wire:key="election-{{ $item->id }}" aria-labelledby="election-{{ $item->id }}-name"
                         class="border-t border-border-default pt-8" data-test="election-{{ $item->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                        <div class="min-w-0">
                            <h2 id="election-{{ $item->id }}-name" class="text-2xl font-bold leading-tight text-text-primary break-words">{{ $item->name }}</h2>
                            <p class="mt-1 text-sm text-text-secondary">
                                @if ($item->meeting_at)
                                    {{ __('Sitzung am :date', ['date' => $this->longDate($item->meeting_at)]) }}
                                @else
                                    {{ __('Sitzungstermin noch offen') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($item->is_test)
                                <flux:badge color="amber" icon="beaker" class="max-w-full whitespace-normal!">{{ __('Probewahl, nur für den Vorstand sichtbar') }}</flux:badge>
                            @endif
                            @unless ($isSelected)
                                <flux:button wire:click="selectElection({{ $item->id }})" class="min-h-11" data-test="select-election-{{ $item->id }}">{{ __('Wahl öffnen') }}</flux:button>
                            @endunless
                        </div>
                    </div>

                    <x-election-run-sheet :item="$item" :step="$step" :selected="$isSelected" :creating="$creating" />

                    @if ($isSelected)
                        @php
                            $pendingCount = $item->rounds->sum(fn ($round) => $round->candidates->where('status', \App\Enums\ElectionCandidateStatus::Pending)->count());
                            $tabs = [
                                'settings' => [__('Einstellungen'), null],
                                'candidacies' => [__('Kandidaturen'), $pendingCount > 0 ? __(':count offen', ['count' => $pendingCount]) : null],
                                'roll' => [__('Wählerverzeichnis'), null],
                                'live' => [__('Sitzung'), $item->rounds->contains(fn ($round) => $round->stateAt(now()) === \App\Enums\ElectionRoundState::Open) ? __('läuft') : null],
                            ];
                        @endphp
                        <nav class="mt-10 flex flex-wrap gap-x-2 border-b border-border-default" aria-label="{{ __('Bereiche von „:name“', ['name' => $item->name]) }}">
                            @foreach ($tabs as $key => [$tabLabel, $tabCount])
                                <button type="button" wire:click="showSection('{{ $key }}')" wire:key="tab-{{ $key }}"
                                        @if ($section === $key) aria-current="page" @endif
                                        data-test="tab-{{ $key }}"
                                        @class([
                                            '-mb-px min-h-11 border-b-2 px-3 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                                            'border-orange-500 font-bold text-text-primary' => $section === $key,
                                            'border-transparent text-text-secondary hover:text-text-primary' => $section !== $key,
                                        ])>
                                    {{ $tabLabel }}@if ($tabCount)<span class="ml-2 text-sm font-normal text-warning">{{ $tabCount }}</span>@endif
                                </button>
                            @endforeach
                        </nav>

                        <div class="pt-8" data-test="work-area">
                            @if ($section === 'settings')
                                @if ($creating)
                                    <p class="text-base text-text-secondary">{{ __('Speichert zuerst die neue Wahl oder brecht sie ab.') }}</p>
                                @else
                                    <livewire:association.election.admin-form :election-id="$item->id" :key="'settings-'.$item->id.($item->announced_at === null ? '' : '-announced')" />
                                @endif
                            @elseif ($section === 'candidacies')
                                <livewire:association.election.admin-candidacies :election-id="$item->id" :key="'candidacies-'.$item->id" />
                            @elseif ($section === 'live')
                                @include('livewire.association.election.admin-live', ['election' => $item])
                            @else
                                @include('livewire.association.election.admin-roll', ['election' => $item])
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        @if ($election !== null)
            <flux:modal name="announce-election" class="w-full max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('„:name“ ankündigen?', ['name' => $election->name]) }}</flux:heading>
                        <flux:text class="mt-2">
                            @if ($election->is_test)
                                {{ __('Die Probewahl erscheint mit ihren :count Wahlgängen auf der Wahlseite, aber nur für den Vorstand.', ['count' => $election->rounds->count()]) }}
                            @else
                                {{ __('Alle Mitglieder sehen danach die Wahl mit ihren :count Wahlgängen und können kandidieren. Die Ankündigung lässt sich nicht zurücknehmen.', ['count' => $election->rounds->count()]) }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <flux:modal.close>
                            <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="announce" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="confirm-announce">{{ __('Wahl ankündigen') }}</flux:button>
                    </div>
                </div>
            </flux:modal>

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

            @if ($election->is_test)
                <flux:modal name="purge-election" class="w-full max-w-lg">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Probewahl „:name“ löschen?', ['name' => $election->name]) }}</flux:heading>
                            <flux:text class="mt-2">{{ __('Gelöscht werden die Wahl, alle Wahlgänge, Kandidaturen, Verzeichnisse, abgegebenen Stimmen, Prüfsummen und Protokollzeilen. Das lässt sich nicht zurücknehmen.') }}</flux:text>
                        </div>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <flux:modal.close>
                                <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                            </flux:modal.close>
                            <flux:button wire:click="purgeTestElection" variant="danger" class="min-h-11 w-full sm:w-auto" data-test="confirm-purge">{{ __('Probewahl löschen') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif

            <flux:modal name="delete-election" class="w-full max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('„:name“ löschen?', ['name' => $election->name]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ trans_choice('{0} Die Wahl und ihre Wahlgänge werden gelöscht.|{1} Die Wahl, ihre Wahlgänge und eine Kandidatur werden gelöscht.|[2,*] Die Wahl, ihre Wahlgänge und :count Kandidaturen werden gelöscht.', $election->rounds->sum(fn ($round) => $round->candidates->count()), ['count' => $election->rounds->sum(fn ($round) => $round->candidates->count())]) }}
                            {{ __('Das Protokoll der Wahl bleibt.') }}
                        </flux:text>
                    </div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <flux:modal.close>
                            <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="deleteElection" variant="danger" class="min-h-11 w-full sm:w-auto" data-test="confirm-delete">{{ __('Wahl löschen') }}</flux:button>
                    </div>
                </div>
            </flux:modal>

        @endif
    @endif
</div>
