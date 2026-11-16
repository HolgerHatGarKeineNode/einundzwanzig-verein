<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\Candidacies;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionSetup;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\EnrolByHand;
use App\Services\Election\OpenRound;
use App\Services\Election\PurgeTestElection;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\RoundReport;
use App\Services\Election\TallyRound;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
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
    private const SECTIONS = ['settings', 'candidacies', 'roll', 'live'];

    private const DEFAULT_CLOSE_REASON = 'Abstimmung in der Sitzung beendet.';

    private const MAX_ROUNDS = 6;

    #[Locked]
    public bool $isGuest = false;

    #[Locked]
    public ?int $selectedElectionId = null;

    #[Locked]
    public bool $creating = false;

    public string $section = 'settings';

    /**
     * @var array{name: string, meeting_date: string, meeting_time: string, call_url: string, fee_rule: string, fee_year: int|string|null, honorary_without_fee: bool, is_test: bool, rounds: list<array{id: int|null, title: string, seats: int|string, counting_rule: string}>}|array{}
     */
    public array $form = [];

    #[Locked]
    public ?int $decisionCandidateId = null;

    #[Locked]
    public ?string $decisionKind = null;

    public string $decisionReason = '';

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

    public string $castingNote = '';

    public bool $runOffResolved = false;

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->actor();

        $first = $this->elections->first();

        if ($first !== null) {
            $this->select($first);
        }
    }

    public function startCreate(): void
    {
        $this->actor();

        $this->resetErrorBag();
        $this->creating = true;
        $this->form = $this->blankForm();
    }

    public function cancelCreate(): void
    {
        $this->actor();

        $this->resetErrorBag();
        $this->creating = false;
        $selected = $this->selectedElection;
        $this->form = $selected === null ? [] : $this->formOf($selected);
    }

    public function selectElection(int $electionId): void
    {
        $this->actor();

        $this->creating = false;
        $this->select(Election::query()->findOrFail($electionId));
    }

    public function showSection(string $section): void
    {
        $this->actor();

        $this->section = in_array($section, self::SECTIONS, true) ? $section : 'settings';
    }

    public function addRound(): void
    {
        $this->actor();

        if (count($this->form['rounds'] ?? []) >= self::MAX_ROUNDS) {
            return;
        }

        $this->form['rounds'][] = ['id' => null, 'title' => '', 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority->value];
    }

    public function removeRound(int $index): void
    {
        $this->actor();

        $rounds = $this->form['rounds'] ?? [];
        unset($rounds[$index]);
        $this->form['rounds'] = array_values($rounds);
    }

    public function save(ElectionSetup $setup): void
    {
        $actor = $this->actor();

        $data = $this->validate($this->formRules(), $this->formMessages())['form'];

        foreach ($data['rounds'] as $index => $round) {
            if ($round['counting_rule'] === ElectionCountingRule::SingleMajority->value && (int) $round['seats'] !== 1) {
                $this->addError("form.rounds.{$index}.seats", __('Eine Einzelwahl vergibt genau einen Sitz. Für mehrere Sitze passt die Bestätigungs- oder Mehrfachwahl.'));

                return;
            }
        }

        $feeRule = ElectionFeeRule::from($data['fee_rule']);
        $attributes = [
            'name' => $data['name'],
            'meeting_at' => $this->meetingAtOf($data),
            'call_url' => $data['call_url'] ?? null,
            'fee_rule' => $feeRule,
            'fee_year' => $feeRule->needsFeeYear() ? (int) $data['fee_year'] : null,
            'honorary_without_fee' => (bool) $data['honorary_without_fee'],
            'is_test' => (bool) $data['is_test'],
        ];
        $rounds = array_map(fn (array $round): array => [
            'id' => filled($round['id'] ?? null) ? (int) $round['id'] : null,
            'title' => $round['title'],
            'seats' => (int) $round['seats'],
            'counting_rule' => $round['counting_rule'],
        ], array_values($data['rounds']));

        try {
            if ($this->creating) {
                $election = $setup->create($actor, $attributes, $rounds);
                $this->creating = false;
                unset($this->elections);
                $this->select($election);
                Flux::toast(__('Entwurf „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');

                return;
            }

            $election = $setup->update($this->requireSelected(), $actor, $attributes, $rounds);
        } catch (ElectionRoundStateException) {
            $this->addError('form', __('Ein Wahlgang dieser Wahl ist schon geöffnet. Danach lässt sich die Wahl nicht mehr ändern.'));

            return;
        } catch (ElectionActionRefusedException) {
            $this->addError('form.rounds', __('Ein Wahlgang mit Kandidaturen lässt sich nicht entfernen. Lehnt sie vorher ab oder zieht sie zurück.'));

            return;
        }

        $this->refreshElection($election);
        Flux::toast(__('Änderungen an „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');
    }

    public function announce(ElectionStewardship $stewardship): void
    {
        $election = $stewardship->announce($this->requireSelected(), $this->actor());

        Flux::modal('announce-election')->close();
        $this->refreshElection($election);
        Flux::toast(__('„:name“ ist angekündigt.', ['name' => $election->name]), variant: 'success');
    }

    public function deleteElection(ElectionStewardship $stewardship): void
    {
        $election = $this->requireSelected();
        $stewardship->delete($election, $this->actor());

        Flux::modal('delete-election')->close();
        $this->selectedElectionId = null;
        $this->form = [];
        unset($this->elections, $this->selectedElection);

        $next = $this->elections->first();

        if ($next !== null) {
            $this->select($next);
        }

        Flux::toast(__('„:name“ ist gelöscht.', ['name' => $election->name]));
    }

    public function approve(int $candidateId, Candidacies $candidacies): void
    {
        $actor = $this->actor();
        $candidate = ElectionCandidate::query()->with('pleb.profile')->findOrFail($candidateId);

        try {
            $candidacies->approve($candidate, $actor);
        } catch (ElectionActionRefusedException) {
            Flux::toast(
                __(':name ist nach der Beitragsregel dieser Wahl noch nicht wahlberechtigt. Freigeben geht nach der Zahlung. Hat :name bar bezahlt, tragt die Wahlberechtigung unter „Wählerverzeichnis“ von Hand ein.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]),
                variant: 'danger',
            );

            return;
        }

        unset($this->elections, $this->selectedElection, $this->candidaciesByRound);
        Flux::toast(__(':name ist freigegeben.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]), variant: 'success');
    }

    public function startDecision(int $candidateId, string $kind): void
    {
        $this->actor();

        ElectionCandidate::query()->findOrFail($candidateId);

        $this->decisionCandidateId = $candidateId;
        $this->decisionKind = $kind === 'withdraw' ? 'withdraw' : 'reject';
        $this->decisionReason = '';
        $this->resetErrorBag('decisionReason');

        Flux::modal('candidacy-decision')->show();
    }

    public function confirmDecision(Candidacies $candidacies): void
    {
        $actor = $this->actor();

        $this->validate(
            ['decisionReason' => ['required', 'string', 'min:3', 'max:1000']],
            [
                'decisionReason.required' => __('Nenne einen Grund. Er steht im Protokoll der Wahl.'),
                'decisionReason.min' => __('Nenne einen Grund mit mindestens drei Zeichen.'),
                'decisionReason.max' => __('Der Grund darf höchstens 1000 Zeichen lang sein.'),
            ],
        );

        $candidate = ElectionCandidate::query()->with('pleb.profile')->findOrFail($this->decisionCandidateId);

        if ($this->decisionKind === 'withdraw') {
            $candidacies->withdraw($candidate, $actor, trim($this->decisionReason));
            $message = __('Die Kandidatur von :name ist zurückgezogen.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]);
        } else {
            $candidacies->reject($candidate, $actor, trim($this->decisionReason));
            $message = __('Die Kandidatur von :name ist abgelehnt.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]);
        }

        Flux::modal('candidacy-decision')->close();
        $this->decisionCandidateId = null;
        $this->decisionKind = null;
        $this->decisionReason = '';
        unset($this->elections, $this->selectedElection, $this->candidaciesByRound);
        Flux::toast($message);
    }

    public function enrol(EnrolByHand $enrolByHand): void
    {
        $actor = $this->actor();

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
        $actor = $this->actor();

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
        unset($this->rollPreview, $this->entitleCandidates, $this->candidaciesByRound, $this->elections, $this->selectedElection);
        Flux::toast(__(':name ist für „:election“ als wahlberechtigt eingetragen.', ['name' => $name, 'election' => $election->name]), variant: 'success');
    }

    /**
     * Only the meeting time and the call link — the one change allowed after
     * a round has been opened.
     */
    public function saveMeeting(ElectionSetup $setup): void
    {
        $actor = $this->actor();

        $rules = array_intersect_key($this->formRules(), array_flip(['form.meeting_date', 'form.meeting_time', 'form.call_url']));
        $data = $this->validate($rules, $this->formMessages())['form'];

        $election = $setup->updateMeeting($this->requireSelected(), $actor, $this->meetingAtOf($data), $data['call_url'] ?? null);

        $this->refreshElection($election);
        Flux::toast(__('Termin und Link von „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');
    }

    /**
     * Ask before a round is opened, ended, annulled or repeated. Ending and
     * annulling take a reason, which goes into the audit log.
     */
    public function startRoundAction(int $roundId, string $kind): void
    {
        $this->actor();

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
        $actor = $this->actor();

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
            $this->addError('roundReason', $this->refusalText($action, $exception));

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
        $actor = $this->actor();
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
        $this->actor();
        $round = $this->tieRound($roundId);

        if ($round === null) {
            return;
        }

        $this->tieRoundId = (int) $round->id;
        $this->castingCandidates = [];
        $this->castingChair = '';
        $this->castingNote = '';
        $this->section = 'live';
        $this->resetErrorBag(['castingCandidates', 'castingChair', 'castingNote']);

        Flux::modal('casting-vote')->show();
    }

    public function recordCastingVote(RecordCastingVote $castingVotes, TallyRound $tally): void
    {
        $actor = $this->actor();
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

            $castingVotes->handle($round, $actor, array_map(intval(...), $this->castingCandidates), $this->castingChair, $this->castingNote);
        } catch (\UnexpectedValueException) {
            $this->addError('castingCandidates', $this->unreadableText($round));

            return;
        } catch (ElectionActionRefusedException|ElectionRoundStateException) {
            $this->addError('castingCandidates', __('Für „:round“ lässt sich kein Stichentscheid eintragen: Er ist schon eingetragen, es gibt eine Stichwahl, oder der Wahlgang ist annulliert. Lade die Seite neu.', ['round' => $round->title]));

            return;
        }

        Flux::modal('casting-vote')->close();
        $this->tieRoundId = null;
        $this->castingCandidates = [];
        $this->castingChair = '';
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
        $this->actor();
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
        $actor = $this->actor();
        $round = ElectionRound::query()->findOrFail($this->tieRoundId ?? abort(404));

        $this->validate(
            ['runOffResolved' => ['accepted']],
            ['runOffResolved.accepted' => __('Eine Stichwahl gibt es bei Gleichstand nur, wenn die Versammlung sie beschlossen hat. Sonst fällt die/der Vorsitzende den Stichentscheid.')],
        );

        try {
            $runOff = $runOffs->handle($round, $actor, resolvedByAssembly: true);
        } catch (ElectionActionRefusedException|ElectionRoundStateException $exception) {
            $this->addError('runOffResolved', str_contains($exception->getMessage(), 'cannot be counted')
                ? $this->unreadableRunOffText($round)
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
        $actor = $this->actor();
        $round = ElectionRound::query()->findOrFail($roundId);

        try {
            // A tie needs the assembly's resolution, given in the dialog of startTieRunOff(); here it is refused.
            $runOff = $runOffs->handle($round, $actor);
        } catch (ElectionActionRefusedException|ElectionRoundStateException $exception) {
            Flux::toast(str_contains($exception->getMessage(), 'cannot be counted')
                ? $this->unreadableRunOffText($round)
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
        $this->actor();
        $election = $this->requireSelected();

        try {
            $purge->handle((int) $election->id);
        } catch (ElectionActionRefusedException) {
            Flux::toast(__('Nur eine Probewahl lässt sich vollständig löschen.'), variant: 'danger');

            return;
        }

        Flux::modal('purge-election')->close();
        $this->selectedElectionId = null;
        $this->form = [];
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
    public function refreshRounds(): void
    {
        $this->actor();

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
        $this->actor();

        $election = $this->selectedElection;

        return $election === null ? collect() : app(RoundReport::class)->forElection($election);
    }

    public function updatedSection(): void
    {
        $this->actor();

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
        $this->actor();

        return Election::query()
            ->with(['rounds' => fn ($rounds) => $rounds->with('candidates:id,election_round_id,status')->withCount('rollEntries')])
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function selectedElection(): ?Election
    {
        $this->actor();

        return $this->selectedElectionId === null
            ? null
            : $this->elections->firstWhere('id', $this->selectedElectionId);
    }

    /**
     * The candidacies of the selected election, per first-level round,
     * undecided first.
     *
     * @return Collection<int, array{round: ElectionRound, candidates: Collection<int, array{candidate: ElectionCandidate, name: string, entitled: bool}>}>
     */
    #[Computed]
    public function candidaciesByRound(): Collection
    {
        $this->actor();

        $election = $this->selectedElection;

        if ($election === null) {
            return collect();
        }

        $entitled = app(ElectoralRoll::class)->entitled($election, now())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $order = [ElectionCandidateStatus::Pending->value => 0, ElectionCandidateStatus::Approved->value => 1, ElectionCandidateStatus::Rejected->value => 2, ElectionCandidateStatus::Withdrawn->value => 3];

        return ElectionRound::query()
            ->where('election_event_id', $election->id)
            ->whereNull('parent_round_id')
            ->orderBy('position')
            ->with('candidates.pleb.profile')
            ->get()
            ->map(fn (ElectionRound $round): array => [
                'round' => $round,
                'candidates' => $round->candidates
                    ->sortBy(fn (ElectionCandidate $candidate): string => $order[$candidate->status->value].mb_strtolower(MemberProposal::authorNameOf($candidate->pleb)))
                    ->values()
                    ->map(fn (ElectionCandidate $candidate): array => [
                        'candidate' => $candidate,
                        'name' => MemberProposal::authorNameOf($candidate->pleb),
                        'entitled' => in_array((int) $candidate->einundzwanzig_pleb_id, $entitled, true),
                    ]),
            ]);
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
        $this->actor();

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
        $this->actor();

        return $this->selectedElection?->rounds->whereNotNull('opened_at')->values() ?? collect();
    }

    #[Computed]
    public function rollRound(): ?ElectionRound
    {
        $this->actor();

        return $this->openedRounds->firstWhere('id', $this->rollRoundId) ?? $this->openedRounds->last();
    }

    /**
     * @return Collection<int, array{name: string, reason: ElectionRollReason, source: ElectionRollSource, signed_in: CarbonInterface|null}>
     */
    #[Computed]
    public function rollEntries(): Collection
    {
        $this->actor();

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
        $this->actor();

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
        $this->actor();

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
     * The five stations of the run sheet, each done, current or ahead.
     *
     * @return list<array{label: string, when: string, state: 'done'|'current'|'ahead'}>
     */
    protected function stationsOf(Election $election): array
    {
        $phase = $this->phaseOf($election);
        $labels = [
            [__('Entwurf'), __('angelegt :date', ['date' => $this->shortDate($election->created_at)])],
            [__('Angekündigt'), $election->announced_at === null ? __('noch nicht') : $this->shortDate($election->announced_at)],
            [__('Kandidatur'), __('bis zur Sitzung')],
            [__('Sitzung live'), $election->meeting_at === null ? __('Termin fehlt') : $this->shortDate($election->meeting_at).', '.$this->clock($election->meeting_at)],
            [__('Ergebnis'), __('nach der Sitzung')],
        ];

        return array_map(fn (int $index): array => [
            'label' => $labels[$index][0],
            'when' => $labels[$index][1],
            'state' => match (true) {
                $index === $phase => 'current',
                $index < $phase => 'done',
                default => 'ahead',
            },
        ], array_keys($labels));
    }

    /**
     * The one next step of an election: what to do, why, and what does it —
     * a section to show (`action`), a Livewire call (`click`) or a page
     * (`href`); all null when the step lies outside this page.
     *
     * In the meeting it walks the board through the evening: open a round,
     * close it, show the result, set up a run-off if it ended level, open the
     * next round, and at the end the minutes. It never offers to open a round
     * `OpenRound` would refuse: it names the reason and the way out instead —
     * the candidacies, or striking a round nobody can stand in any more.
     *
     * @return array{text: string, hint: string|null, action: string|null, label: string|null, click: string|null, href: string|null, secondary_label: string|null, secondary_click: string|null}
     */
    protected function nextStepOf(Election $election): array
    {
        // A struck round is void: it holds no step.
        $rounds = $election->rounds->whereNull('parent_round_id')->whereNull('annulled_at');
        $phase = $this->phaseOf($election);

        if ($phase === 0) {
            if ($rounds->isEmpty()) {
                return $this->step(__('Lege mindestens einen Wahlgang an.'), __('Ohne Wahlgang lässt sich die Wahl nicht ankündigen.'), action: 'settings', label: __('Wahlgang anlegen'));
            }

            if ($election->meeting_at === null) {
                return $this->step(__('Trage den Sitzungstermin ein.'), __('Gewählt wird live in dieser Sitzung. Mitglieder sehen den Termin mit der Ankündigung.'), action: 'settings', label: __('Termin eintragen'));
            }

            return $this->step(
                __('Wahl ankündigen.'),
                $election->is_test
                    ? __('Probewahl: auch angekündigt sieht sie nur der Vorstand.')
                    : __('Danach sehen alle Mitglieder die Wahl und können kandidieren.'),
                action: 'announce',
                label: __('Wahl ankündigen'),
            );
        }

        if ($phase === 2) {
            $pending = $rounds->sum(fn (ElectionRound $round): int => $round->candidates->where('status', ElectionCandidateStatus::Pending)->count());

            if ($pending > 0) {
                return $this->step(
                    trans_choice('{1} Eine Kandidatur wartet auf Prüfung.|[2,*] :count Kandidaturen warten auf Prüfung.', $pending, ['count' => $pending]),
                    __('Freigeben geht, sobald die Person nach der Beitragsregel wahlberechtigt ist.'),
                    action: 'candidacies',
                    label: __('Kandidaturen prüfen'),
                );
            }

            $without = $rounds->filter(fn (ElectionRound $round): bool => $round->candidates->where('status', ElectionCandidateStatus::Approved)->isEmpty());
            $first = $rounds->sortBy('position')->first();

            // Candidacies stay open until the meeting: only the round that is opened first is a dead end before the evening.
            // A later round without a candidacy is mentioned on the ready step below, without the offer to strike it.
            $laterWithout = $without->reject(fn (ElectionRound $round): bool => $round->is($first))->sortBy('position')->values();
            $without = $without->first(fn (ElectionRound $round): bool => $round->is($first));

            if ($without !== null) {
                return $this->step(
                    __('„:round“ hat noch keine freigegebene Kandidatur.', ['round' => $without->title]),
                    __('Ohne freigegebene Kandidatur lässt sich ein Wahlgang in der Sitzung nicht öffnen. Kann niemand mehr antreten, streicht den Wahlgang.'),
                    action: 'candidacies',
                    label: __('Kandidaturen ansehen'),
                    secondaryLabel: __('Wahlgang streichen'),
                    secondaryClick: "startRoundAction({$without->id}, 'strike')",
                );
            }
        }

        $all = $election->rounds->sortBy('position')->values();
        $now = now();

        if ($all->isEmpty()) {
            return $this->step(
                __('Diese Wahl hat noch keinen Wahlgang.'),
                __('Legt unter „Einstellungen“ mindestens einen Wahlgang an.'),
                action: 'settings',
                label: __('Wahlgänge anlegen'),
            );
        }

        $open = $all->first(fn (ElectionRound $round): bool => $round->stateAt($now) === ElectionRoundState::Open);

        if ($open !== null) {
            return $this->step(
                __('„:round“ ist offen.', ['round' => $open->title]),
                __('Die Beteiligung steht unter „Sitzung“ und in der Ansicht zum Teilen. Schließt den Wahlgang, wenn alle abgestimmt haben, die abstimmen wollen.'),
                label: __('Wahlgang schließen'),
                click: "startRoundAction({$open->id}, 'close')",
            );
        }

        $overByClock = $all->first(fn (ElectionRound $round): bool => $round->stateAt($now) === ElectionRoundState::Closed && ! $round->isSealed());

        if ($overByClock !== null) {
            return $this->step(
                __('„:round“ nimmt nach :minutes Minuten keine Stimmen mehr an.', ['round' => $overByClock->title, 'minutes' => \App\Services\Election\OpenRound::LIVE_MAX_MINUTES]),
                __('Zählt ihn aus, dann seht ihr das Ergebnis.'),
                label: __('Auszählen'),
                click: "sealRound({$overByClock->id})",
            );
        }

        $runOffs = app(CreateRunOff::class);
        $proposals = [];
        $level = $all->first(function (ElectionRound $round) use ($all, $runOffs, &$proposals): bool {
            if (! $round->isSealed() || $all->where('parent_round_id', $round->id)->whereNull('annulled_at')->isNotEmpty()) {
                return false;
            }

            $proposals[$round->id] = $runOffs->proposal($round);

            return $proposals[$round->id]['candidates'] !== [];
        });

        // A tie: the chair casts the deciding vote (statutes Art. 6); a run-off only if the assembly resolves one.
        if ($level !== null && $proposals[$level->id]['needs_resolution']) {
            return $this->step(
                __('„:round“ endet im Gleichstand.', ['round' => $level->title]),
                __('Nach den Statuten fällt die/der Vorsitzende den Stichentscheid. Tragt ihn ein, sobald er gefallen ist. Eine Stichwahl gibt es nur, wenn die Versammlung sie beschließt.'),
                label: __('Stichentscheid eintragen'),
                click: "startCastingVote({$level->id})",
                secondaryLabel: __('Stichwahl anlegen'),
                secondaryClick: "startTieRunOff({$level->id})",
            );
        }

        if ($level !== null) {
            return $this->step(
                __('„:round“ ist ohne Entscheidung ausgezählt.', ['round' => $level->title]),
                __('Zeigt das Ergebnis im Call. Die Stichwahl ist danach ein eigener Wahlgang zwischen den Betroffenen.'),
                label: __('Stichwahl anlegen'),
                click: "createRunOff({$level->id})",
            );
        }

        // An annulled round is void, also a run-off annulled along with its round before it was opened.
        $openable = $all->filter(fn (ElectionRound $round): bool => $round->opened_at === null && $round->annulled_at === null);
        $next = $openable->first(fn (ElectionRound $round): bool => $round->parent_round_id !== null) ?? $openable->first();
        $stewardship = app(ElectionStewardship::class);
        $awaiting = $all->first(fn (ElectionRound $round): bool => $stewardship->awaitsRepeat($round));

        if ($next !== null && ($refusal = app(OpenRound::class)->refusal($next)) !== null) {
            $withoutCandidates = str_contains($refusal->getMessage(), 'without approved candidates');
            $strike = $next->parent_round_id === null ? "startRoundAction({$next->id}, 'strike')" : null;

            return $this->step(
                __('„:round“ lässt sich nicht öffnen.', ['round' => $next->title])
                    .($awaiting === null ? '' : ' '.__('„:round“ wartet noch auf die Wiederholung.', ['round' => $awaiting->title])),
                $this->refusalText('open', $refusal),
                action: $withoutCandidates && $strike !== null ? null : 'candidacies',
                label: $withoutCandidates && $strike !== null ? __('Wahlgang streichen') : __('Kandidaturen ansehen'),
                click: $withoutCandidates ? $strike : null,
                secondaryLabel: $strike === null ? null : ($withoutCandidates ? __('Kandidaturen ansehen') : __('Wahlgang streichen')),
                secondaryClick: $strike === null ? null : ($withoutCandidates ? "showSection('candidacies')" : $strike),
            );
        }

        if ($next !== null) {
            $last = $all->filter(fn (ElectionRound $round): bool => $round->opened_at !== null && ($round->closed_at !== null || $round->annulled_at !== null))
                // Latest finished first; within one second the round itself before a run-off annulled along with it, then the later created.
                ->sort(fn (ElectionRound $a, ElectionRound $b): int => [($b->annulled_at ?? $b->closed_at)->getTimestamp(), $b->parent_round_id === null ? 1 : 0, $b->id] <=> [($a->annulled_at ?? $a->closed_at)->getTimestamp(), $a->parent_round_id === null ? 1 : 0, $a->id])
                ->first();

            return $this->step(
                match (true) {
                    $last?->isSealed() === true => __('„:last“ ist ausgezählt. Als Nächstes: „:round“.', ['last' => $last->title, 'round' => $next->title]),
                    $last !== null => __('„:last“ ist annulliert. Als Nächstes: „:round“.', ['last' => $last->title, 'round' => $next->title]),
                    $election->meeting_at !== null && ! $election->is_test => __('Bereit für die Sitzung am :date. Zuerst: „:round“.', ['date' => $this->longDate($election->meeting_at), 'round' => $next->title]),
                    default => __('Bereit. Zuerst: „:round“.', ['round' => $next->title]),
                }.($awaiting === null ? '' : ' '.__('„:round“ wartet noch auf die Wiederholung.', ['round' => $awaiting->title])),
                match (true) {
                    $last?->isSealed() === true => $this->seatsLeftOpenText($last).__('Zeigt zuerst das Ergebnis im Call, dann öffnet den nächsten Wahlgang.'),
                    $election->is_test => __('Probewahl: öffnet den Wahlgang, wann immer ihr üben wollt.'),
                    default => __('Öffnet ihn erst in der Sitzung. Ab dann können die Wahlberechtigten abstimmen.'),
                }.(($laterWithout ?? collect())->isEmpty() ? '' : ' '.trans_choice(
                    '{1} „:rounds“ hat noch keine freigegebene Kandidatur. Kandidieren geht bis zur Sitzung.|[2,*] „:rounds“ haben noch keine freigegebene Kandidatur. Kandidieren geht bis zur Sitzung.',
                    $laterWithout->count(),
                    ['rounds' => $laterWithout->pluck('title')->join('“, „', '“ und „')],
                )),
                label: __('„:round“ öffnen', ['round' => $next->title]),
                click: "startRoundAction({$next->id}, 'open')",
            );
        }

        $annulled = $awaiting;

        if ($annulled !== null) {
            return $this->step(
                __('„:round“ ist annulliert und noch nicht wiederholt.', ['round' => $annulled->title]),
                __('Wiederholt ihn, wenn neu abgestimmt werden soll, mit denselben freigegebenen Kandidaturen. Das Beschlussprotokoll findet ihr unter „Sitzung“.'),
                label: __('Wahlgang wiederholen'),
                click: "startRoundAction({$annulled->id}, 'repeat')",
            );
        }

        return $this->step(
            __('Alle Wahlgänge sind abgeschlossen.'),
            $this->seatsLeftOpenText($all->filter(fn (ElectionRound $round): bool => $round->isSealed())->sortByDesc('closed_at')->first()).__('Das Beschlussprotokoll fasst Wahlgänge, Zahlen und Prüfsummen für die Niederschrift zusammen.'),
            label: __('Beschlussprotokoll öffnen'),
            href: route('association.election.admin.protocol', $election),
        );
    }

    /**
     * @return array{text: string, hint: string|null, action: string|null, label: string|null, click: string|null, href: string|null, secondary_label: string|null, secondary_click: string|null}
     */
    protected function step(string $text, ?string $hint = null, ?string $action = null, ?string $label = null, ?string $click = null, ?string $href = null, ?string $secondaryLabel = null, ?string $secondaryClick = null): array
    {
        return ['text' => $text, 'hint' => $hint, 'action' => $action, 'label' => $label, 'click' => $click, 'href' => $href, 'secondary_label' => $secondaryLabel, 'secondary_click' => $secondaryClick];
    }


    protected function countingRuleLabel(string|ElectionCountingRule $rule): string
    {
        return match (is_string($rule) ? ElectionCountingRule::tryFrom($rule) : $rule) {
            ElectionCountingRule::SingleMajority => __('Einzelwahl'),
            ElectionCountingRule::YesNoPerCandidate => __('Bestätigungswahl'),
            ElectionCountingRule::MostVotes => __('Mehrfachwahl'),
            default => '',
        };
    }

    protected function countingRuleHint(string|ElectionCountingRule $rule): string
    {
        return match (is_string($rule) ? ElectionCountingRule::tryFrom($rule) : $rule) {
            ElectionCountingRule::SingleMajority => __('Eine Stimme. Gewählt ist, wer mehr als die Hälfte der gültigen Stimmen hat.'),
            ElectionCountingRule::YesNoPerCandidate => __('Ja oder Nein je Person. Gewählt ist, wer mehr Ja als Nein hat.'),
            ElectionCountingRule::MostVotes => __('So viele Stimmen wie Sitze. Gewählt sind die am häufigsten Genannten.'),
            default => '',
        };
    }

    protected function feeRuleLabel(ElectionFeeRule $rule, ?int $year): string
    {
        $year ??= (int) now()->year;

        return match ($rule) {
            ElectionFeeRule::Year => __('Beitrag :year', ['year' => $year]),
            ElectionFeeRule::YearOrPrevious => __('Beitrag :year oder :previous', ['year' => $year, 'previous' => $year - 1]),
            ElectionFeeRule::LastTwelveMonths => __('Beitrag in den letzten 12 Monaten'),
        };
    }

    protected function feeRuleHint(ElectionFeeRule $rule, ?int $year): string
    {
        $year ??= (int) now()->year;

        return match ($rule) {
            ElectionFeeRule::Year => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied den Beitrag für :year bezahlt hat.', ['year' => $year]),
            ElectionFeeRule::YearOrPrevious => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied den Beitrag für :year oder für :previous bezahlt hat.', ['year' => $year, 'previous' => $year - 1]),
            ElectionFeeRule::LastTwelveMonths => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied in den zwölf Monaten vor dem Öffnen des Wahlgangs einen Beitrag bezahlt hat.'),
        };
    }

    protected function missingFeeText(Election $election): string
    {
        return match ($election->fee_rule) {
            ElectionFeeRule::Year => __('Beitrag :year fehlt', ['year' => $election->fee_year]),
            ElectionFeeRule::YearOrPrevious => __('Beitrag :year oder :previous fehlt', ['year' => $election->fee_year, 'previous' => (int) $election->fee_year - 1]),
            ElectionFeeRule::LastTwelveMonths => __('Kein Beitrag in den letzten 12 Monaten'),
        };
    }

    protected function reasonLabel(ElectionRollReason $reason): string
    {
        return match ($reason) {
            ElectionRollReason::ActiveFeePaid => __('Aktivmitglied, Beitrag bezahlt'),
            ElectionRollReason::HonoraryFeePaid => __('Ehrenmitglied, Beitrag bezahlt'),
            ElectionRollReason::HonoraryExempt => __('Ehrenmitglied, ohne Beitrag'),
            ElectionRollReason::ConfirmedByBoard => __('Vom Vorstand bestätigt'),
        };
    }

    protected function sourceLabel(ElectionRollSource $source): string
    {
        return match ($source) {
            ElectionRollSource::Opening => __('beim Öffnen'),
            ElectionRollSource::LatePayment => __('durch Nachzahlung'),
            ElectionRollSource::Inherited => __('aus dem Wahlgang übernommen'),
            ElectionRollSource::ByHand => __('von Hand nachgetragen'),
        };
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
     * The meeting moment a form names, read in German time and stored in UTC.
     *
     * @param  array<string, mixed>  $data
     */
    protected function meetingAtOf(array $data): ?CarbonInterface
    {
        if (blank($data['meeting_date'] ?? null)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $data['meeting_date'].' '.(($data['meeting_time'] ?? '') ?: '19:00'), config('app.display_timezone'))
            ->setTimezone(config('app.timezone'));
    }

    protected function refusalText(string $action, ElectionRoundStateException $exception): string
    {
        $message = $exception->getMessage();
        $name = $exception instanceof ElectionCandidateBlockedException
            ? MemberProposal::authorNameOf(EinundzwanzigPleb::query()->with('profile')->find($exception->memberId))
            : null;

        return match (true) {
            $action === 'open' && str_contains($message, 'without approved candidates') => __('Dieser Wahlgang hat keine freigegebene Kandidatur. Gebt unter „Kandidaturen“ eine frei, oder streicht den Wahlgang, wenn niemand mehr antreten kann.'),
            $action === 'open' && $name !== null && str_contains($message, 'stored votes cannot be counted') => $this->unreadableBlockText($name, $exception->memberId),
            // Before the run-off arm, so a future message that also says "run-off" still lands here.
            $action === 'open' && $name !== null && str_contains($message, 'second ballot is owed') => __(':name gehört zu den Bestplatzierten eines früheren Wahlgangs ohne Mehrheit, und dessen Stichwahl steht noch aus. Legt zuerst die Stichwahl an.', ['name' => $name]),
            $action === 'open' && str_contains($message, 'run-off') => __('Die Stichwahl lässt sich erst öffnen, wenn ihr Wahlgang ausgezählt ist.'),
            $action === 'open' && $name !== null && str_contains($message, 'not sealed yet') => __(':name kandidiert auch in einem früheren Wahlgang, der noch nicht ausgezählt ist. Zählt ihn zuerst aus, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name]),
            $action === 'open' && $name !== null && str_contains($message, 'has been elected') => __(':name ist in einem früheren Wahlgang schon gewählt. Zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück, dann lässt sich der Wahlgang öffnen. Kann danach niemand mehr antreten, streicht den Wahlgang.', ['name' => $name]),
            $action === 'open' && $name !== null && str_contains($message, 'undecided tie') => __(':name steht in einem früheren Wahlgang im Gleichstand, und der ist noch nicht entschieden. Tragt den Stichentscheid der/des Vorsitzenden ein, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name]),
            $action === 'open' && str_contains($message, 'fee year') => __('Die Beitragsregel dieser Wahl braucht ein Beitragsjahr. Tragt es unter „Einstellungen“ ein.'),
            $action === 'open' => __('Dieser Wahlgang lässt sich gerade nicht öffnen. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet.'),
            $action === 'close' => __('Der Wahlgang nimmt keine Stimmen mehr an. Lade die Seite neu, vielleicht hat ihn schon jemand geschlossen.'),
            $action === 'strike' => __('Streichen geht nur bei einem Wahlgang, der nie geöffnet wurde. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet oder gestrichen.'),
            default => __('Der Wahlgang ist schon annulliert oder noch nicht geöffnet. Lade die Seite neu.'),
        };
    }

    /**
     * "Ein Sitz bleibt frei." for a counted round that left seats empty with
     * no run-off to follow (one candidate short of the majority, 0 votes, a
     * yes/no round with more no than yes), plus the only way to vote again.
     * Empty for any other round, so it can lead a hint.
     */
    protected function seatsLeftOpenText(?ElectionRound $round): string
    {
        if ($round === null || ! $round->isSealed()) {
            return '';
        }

        $report = app(RoundReport::class)->of($round);
        $result = $report['result'];

        if ($result === null || $result['outcome'] !== ElectionOutcome::Understaffed || $report['run_off'] !== null || $report['has_run_off']) {
            return '';
        }

        $free = $round->seats - collect($result['rows'])->where('elected', true)->count();

        if ($free < 1) {
            return '';
        }

        return trans_choice('{1} In „:round“ bleibt ein Sitz frei.|[2,*] In „:round“ bleiben :count Sitze frei.', $free, ['round' => $round->title, 'count' => $free])
            .' '.__('Neu abstimmen geht nur über „Wahlgang annullieren“ und danach „Wahlgang wiederholen“.').' ';
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
            Flux::toast($this->unreadableText($round), variant: 'danger');

            return null;
        }

        abort_unless($outcome === ElectionOutcome::Tie, 404);

        return $round;
    }

    /**
     * The mark of a round whose stored votes do not fit it, in the words of
     * the minutes: no result, the count is refused until it is verified.
     */
    protected function unreadableText(ElectionRound $round): string
    {
        return __('Ergebnis nicht lesbar: Die gespeicherten Stimmen passen nicht zu diesem Wahlgang. Prüfen mit „php artisan election:verify :round“.', ['round' => $round->id]);
    }

    protected function unreadableRunOffText(ElectionRound $round): string
    {
        return __('Für „:round“ lässt sich keine Stichwahl anlegen: Ergebnis nicht lesbar. Prüft den Wahlgang mit „php artisan election:verify :id“ oder annulliert ihn.', ['round' => $round->title, 'id' => $round->id]);
    }

    /**
     * Opening is refused because `$memberId` also stands in a sealed round of
     * this election whose stored votes cannot be counted. The exception does
     * not carry that round, so it is found here by trying the count.
     */
    protected function unreadableBlockText(string $name, int $memberId): string
    {
        $tally = app(TallyRound::class);
        $round = $this->selectedElection?->rounds()
            ->whereNotNull('closed_at')->whereNull('annulled_at')
            ->whereHas('candidates', fn ($query) => $query->where('einundzwanzig_pleb_id', $memberId))
            ->orderBy('position')->get()
            ->first(function (ElectionRound $round) use ($tally): bool {
                try {
                    $tally->effective($round);
                } catch (\UnexpectedValueException) {
                    return true;
                }

                return false;
            });

        return $round === null
            ? __(':name kandidiert auch in einem früheren Wahlgang, dessen Ergebnis nicht lesbar ist. Prüft ihn mit „php artisan election:verify“ oder annulliert ihn.', ['name' => $name])
            : __(':name kandidiert auch in „:round“, und dessen Ergebnis ist nicht lesbar. Prüft ihn mit „php artisan election:verify :id“ oder annulliert ihn.', ['name' => $name, 'round' => $round->title, 'id' => $round->id]);
    }

    /**
     * The tied candidates of the round in the tie dialogs, with their counts.
     *
     * @return array{round: ElectionRound, kind: ElectionTieKind, seats: int, candidates: list<array{id: int, name: string, for: int, yes: int, no: int}>}|null
     */
    #[Computed]
    public function tie(): ?array
    {
        $this->actor();

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
                ...$result->counts[$id],
            ], $result->tied),
        ];
    }

    protected function canRepeat(ElectionRound $round): bool
    {
        return app(ElectionStewardship::class)->awaitsRepeat($round);
    }

    protected function phaseOf(Election $election): int
    {
        $rounds = $election->rounds;

        if ($rounds->whereNotNull('opened_at')->isNotEmpty()) {
            return $rounds->every(fn (ElectionRound $round): bool => $round->closed_at !== null || $round->annulled_at !== null) ? 4 : 3;
        }

        return $election->announced_at === null ? 0 : 2;
    }

    protected function signedInSinceAnnouncement(Election $election, EinundzwanzigPleb $pleb): ?CarbonInterface
    {
        return $election->announced_at !== null && $pleb->last_login_at !== null && $pleb->last_login_at->gte($election->announced_at)
            ? $pleb->last_login_at
            : null;
    }

    /**
     * The board member behind the session, or 403. Asked on every request,
     * never remembered on the component.
     */
    protected function actor(): EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        abort_unless($pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() && $pleb->isBoardMember(), 403);

        return $pleb;
    }

    protected function requireSelected(): Election
    {
        return Election::query()->findOrFail($this->selectedElectionId ?? abort(404));
    }

    protected function select(Election $election): void
    {
        $this->resetErrorBag();
        $this->selectedElectionId = (int) $election->id;
        unset($this->selectedElection, $this->candidaciesByRound, $this->rollPreview, $this->openedRounds, $this->rollRound, $this->rollEntries, $this->enrolCandidates, $this->roundReports);
        $this->rollRoundId = null;
        $current = $this->selectedElection ?? $election;
        $this->form = $this->formOf($current);
        $step = $this->nextStepOf($current);
        $this->section = match (true) {
            $step['action'] === 'candidacies' => 'candidacies',
            $step['click'] !== null || $this->phaseOf($current) >= 3 => 'live',
            default => 'settings',
        };
    }

    protected function refreshElection(Election $election): void
    {
        unset($this->elections, $this->selectedElection, $this->candidaciesByRound, $this->rollPreview, $this->openedRounds, $this->rollRound, $this->rollEntries, $this->roundReports);
        $this->form = $this->formOf($this->selectedElection ?? $election);
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankForm(): array
    {
        return [
            'name' => '',
            'meeting_date' => '',
            'meeting_time' => '19:00',
            'call_url' => '',
            'fee_rule' => ElectionFeeRule::Year->value,
            'fee_year' => (int) now()->year,
            'honorary_without_fee' => false,
            'is_test' => false,
            'rounds' => [
                ['id' => null, 'title' => __('Präsidium'), 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority->value],
                ['id' => null, 'title' => __('Vorstand'), 'seats' => 4, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate->value],
                ['id' => null, 'title' => __('Revisionsstelle'), 'seats' => 1, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate->value],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOf(Election $election): array
    {
        $election->loadMissing('rounds');

        return [
            'name' => $election->name,
            'meeting_date' => ($election->meeting_at === null ? '' : $this->local($election->meeting_at)->format('Y-m-d')),
            'meeting_time' => ($election->meeting_at === null ? '19:00' : $this->clock($election->meeting_at)),
            'call_url' => (string) $election->call_url,
            'fee_rule' => $election->fee_rule->value,
            'fee_year' => $election->fee_year ?? (int) now()->year,
            'honorary_without_fee' => (bool) $election->honorary_without_fee,
            'is_test' => (bool) $election->is_test,
            'rounds' => $election->rounds->whereNull('parent_round_id')->values()->map(fn (ElectionRound $round): array => [
                'id' => (int) $round->id,
                'title' => $round->title,
                'seats' => (int) $round->seats,
                'counting_rule' => $round->counting_rule->value,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formRules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:120'],
            'form.meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'form.meeting_time' => ['nullable', 'date_format:H:i'],
            'form.call_url' => ['nullable', 'url:https,http', 'max:2048'],
            'form.fee_rule' => ['required', Rule::enum(ElectionFeeRule::class)],
            'form.fee_year' => [Rule::requiredIf(fn (): bool => ($this->form['fee_rule'] ?? '') !== ElectionFeeRule::LastTwelveMonths->value), 'nullable', 'integer', 'between:2020,2100'],
            'form.honorary_without_fee' => ['boolean'],
            'form.is_test' => ['boolean'],
            'form.rounds' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROUNDS],
            'form.rounds.*.id' => ['nullable', 'integer'],
            'form.rounds.*.title' => ['required', 'string', 'max:80'],
            'form.rounds.*.seats' => ['required', 'integer', 'between:1,7'],
            'form.rounds.*.counting_rule' => ['required', Rule::enum(ElectionCountingRule::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function formMessages(): array
    {
        return [
            'form.name.required' => __('Gib der Wahl einen Namen, etwa „Vorstandswahl 2027“.'),
            'form.name.max' => __('Der Name darf höchstens 120 Zeichen lang sein.'),
            'form.meeting_date.date_format' => __('Das Datum ist nicht lesbar. Wähle es im Kalender.'),
            'form.meeting_time.date_format' => __('Die Uhrzeit ist nicht lesbar, etwa 19:00.'),
            'form.call_url.*' => __('Der Link zum Video-Call muss eine vollständige Adresse sein, die mit https:// beginnt.'),
            'form.fee_rule.*' => __('Wähle eine Beitragsregel.'),
            'form.fee_year.required' => __('Diese Beitragsregel braucht ein Beitragsjahr.'),
            'form.fee_year.*' => __('Das Beitragsjahr ist eine Jahreszahl zwischen 2020 und 2100.'),
            'form.rounds.required' => __('Lege mindestens einen Wahlgang an.'),
            'form.rounds.min' => __('Lege mindestens einen Wahlgang an.'),
            'form.rounds.max' => __('Eine Wahl hat höchstens :max Wahlgänge.', ['max' => self::MAX_ROUNDS]),
            'form.rounds.*.title.required' => __('Gib dem Wahlgang einen Titel, etwa „Präsidium“.'),
            'form.rounds.*.title.max' => __('Der Titel darf höchstens 80 Zeichen lang sein.'),
            'form.rounds.*.seats.*' => __('Ein Wahlgang vergibt 1 bis 7 Sitze.'),
            'form.rounds.*.counting_rule.*' => __('Wähle eine Zählregel.'),
        ];
    }
};
?>

@php
    $election = $this->isGuest ? null : $this->selectedElection;
    $locked = $election !== null && $election->rounds->whereNotNull('opened_at')->isNotEmpty();
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
        <header class="mb-12 flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <div class="max-w-2xl">
                <h1 class="text-3xl font-bold leading-tight text-text-primary">{{ __('Wahl leiten') }}</h1>
                <p class="mt-2 text-base text-text-secondary">
                    {{ __('Der Vorstand bereitet hier die Wahl vor: Termin, Wahlgänge, Kandidaturen und das Verzeichnis der Wahlberechtigten. Gewählt wird live in der Sitzung.') }}
                </p>
            </div>
            @unless ($creating)
                <flux:button wire:click="startCreate" icon="plus" class="min-h-11" :variant="$this->elections->isEmpty() ? 'primary' : 'outline'" data-test="start-create">
                    {{ __('Wahl anlegen') }}
                </flux:button>
            @endunless
        </header>

        @if ($creating)
            <section aria-labelledby="create-heading" class="mb-12 rounded-xl border border-border-default bg-bg-surface p-4 sm:p-8" data-test="create-panel">
                <h2 id="create-heading" class="text-2xl font-bold text-text-primary">{{ __('Neue Wahl') }}</h2>
                <p class="mt-2 max-w-2xl text-base text-text-secondary">{{ __('Die Wahl bleibt ein Entwurf, bis ihr sie ankündigt. Mitglieder sehen sie erst danach.') }}</p>
                <div class="mt-8">
                    @include('livewire.association.election.admin-form', ['isNew' => true, 'locked' => false])
                </div>
            </section>
        @endif

        @if ($this->elections->isEmpty() && ! $creating)
            <div class="max-w-2xl rounded-xl border border-dashed border-border-default px-5 py-8" data-test="empty-state">
                <p class="text-lg font-semibold text-text-primary">{{ __('Noch keine Wahl angelegt.') }}</p>
                <p class="mt-2 text-base text-text-secondary">{{ __('Legt die Wahl an, sobald der Sitzungstermin feststeht. Präsidium, Vorstand und Revisionsstelle sind als Wahlgänge vorbereitet.') }}</p>
            </div>
        @endif

        <div class="space-y-12">
            @foreach ($this->elections as $item)
                @php
                    $isSelected = $item->id === $selectedElectionId;
                    $step = $this->nextStepOf($item);
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

                    {{-- The run sheet: five stations of a real sequence, so they are an ordered list. --}}
                    <ol class="mt-6 grid gap-x-2 gap-y-1 md:grid-cols-5" aria-label="{{ __('Ablaufplan von „:name“', ['name' => $item->name]) }}" data-test="phase-rail">
                        @foreach ($this->stationsOf($item) as $station)
                            <li @class([
                                    'grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-3 py-1 md:block md:py-0',
                                ])
                                @if ($station['state'] === 'current') aria-current="step" @endif
                                data-state="{{ $station['state'] }}">
                                <span aria-hidden="true" @class([
                                    'hidden md:block h-1 rounded-full mb-3',
                                    'bg-neutral-500' => $station['state'] === 'done',
                                    'bg-orange-500' => $station['state'] === 'current',
                                    'bg-border-default' => $station['state'] === 'ahead',
                                ])></span>
                                <span class="flex md:hidden" aria-hidden="true">
                                    @if ($station['state'] === 'done')
                                        <flux:icon.check-circle variant="mini" class="size-5 text-text-secondary" />
                                    @elseif ($station['state'] === 'current')
                                        <flux:icon.play-circle variant="mini" class="size-5 text-orange-500" />
                                    @else
                                        <span class="m-0.5 block size-4 rounded-full border-2 border-neutral-500"></span>
                                    @endif
                                </span>
                                <span @class([
                                    'text-base md:block',
                                    'font-bold text-text-primary' => $station['state'] === 'current',
                                    'text-text-secondary' => $station['state'] === 'done',
                                    'text-text-tertiary' => $station['state'] === 'ahead',
                                ])>
                                    @if ($station['state'] === 'done')
                                        <flux:icon.check variant="micro" class="hidden md:inline size-4 -mt-0.5 text-text-secondary" aria-hidden="true" />
                                    @endif
                                    {{ $station['label'] }}
                                    <span class="sr-only">
                                        @if ($station['state'] === 'done') {{ __('(erledigt)') }} @elseif ($station['state'] === 'current') {{ __('(jetzt)') }} @else {{ __('(folgt)') }} @endif
                                    </span>
                                </span>
                                <span @class([
                                    'text-sm text-right md:text-left md:block md:mt-0.5',
                                    'text-warning' => $station['when'] === __('Termin fehlt'),
                                    'text-text-tertiary' => $station['when'] !== __('Termin fehlt'),
                                ])>
                                    @if ($station['state'] === 'current')<span class="font-bold text-orange-500">{{ __('jetzt') }}</span><span class="text-text-tertiary">, </span>@endif{{ $station['when'] }}
                                </span>
                            </li>
                        @endforeach
                    </ol>

                    <div class="mt-6 flex flex-col gap-4 rounded-r-lg border-l-4 border-orange-500 bg-bg-elevated p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6"
                         data-test="next-step">
                        <div class="min-w-0 max-w-2xl">
                            <p class="text-lg text-text-primary"><span class="font-bold">{{ __('Nächster Schritt:') }}</span> {{ $step['text'] }}</p>
                            @if ($step['hint'])
                                <p class="mt-1 text-sm text-text-secondary">{{ $step['hint'] }}</p>
                            @endif
                        </div>
                        @php $primary = $isSelected && ! $creating; @endphp
                        <div class="flex shrink-0 flex-col gap-2 sm:flex-row-reverse sm:items-center">
                        @if ($step['href'] !== null)
                            <flux:button :href="$step['href']" target="_blank" :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-action">{{ $step['label'] }}</flux:button>
                        @elseif ($step['click'] !== null)
                            <flux:button wire:click="{{ $isSelected ? $step['click'] : 'selectElection('.$item->id.')' }}"
                                         :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto sm:max-w-sm" data-test="next-step-action">
                                {{ $isSelected ? $step['label'] : __('Wahl öffnen') }}
                            </flux:button>
                        @elseif ($step['action'] !== null)
                            @php
                                $stepClick = $isSelected ? "showSection('".$step['action']."')" : 'selectElection('.$item->id.')';
                            @endphp
                            @if ($step['action'] === 'announce')
                                @if ($isSelected)
                                    <flux:modal.trigger name="announce-election">
                                        <flux:button :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-action">{{ $step['label'] }}</flux:button>
                                    </flux:modal.trigger>
                                @else
                                    <flux:button wire:click="selectElection({{ $item->id }})" class="min-h-11 w-full shrink-0 sm:w-auto">{{ $step['label'] }}</flux:button>
                                @endif
                            @else
                                <flux:button
                                    wire:click="{{ $stepClick }}"
                                    :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-action">
                                    {{ $step['label'] }}
                                </flux:button>
                            @endif
                        @endif
                        @if ($isSelected && $step['secondary_click'] !== null)
                            <flux:button wire:click="{{ $step['secondary_click'] }}" variant="ghost" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-secondary">{{ $step['secondary_label'] }}</flux:button>
                        @endif
                        </div>
                    </div>

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
                                    @include('livewire.association.election.admin-form', ['isNew' => false, 'locked' => $locked])
                                @endif
                            @elseif ($section === 'candidacies')
                                @include('livewire.association.election.admin-candidacies', ['election' => $item])
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
                                                    <span class="min-w-0 flex-1 font-bold break-words text-text-primary">{{ $candidate['name'] }}</span>
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

            @php
                $decisionCandidate = $decisionCandidateId ? \App\Models\ElectionCandidate::query()->with(['pleb.profile', 'round'])->find($decisionCandidateId) : null;
            @endphp
            <flux:modal name="candidacy-decision" class="w-full max-w-lg">
                <form wire:submit="confirmDecision" class="space-y-6">
                    <div>
                        <flux:heading size="lg">
                            @if ($decisionCandidate)
                                {{ $decisionKind === 'withdraw'
                                    ? __('Kandidatur von :name zurückziehen?', ['name' => MemberProposal::authorNameOf($decisionCandidate->pleb)])
                                    : __('Kandidatur von :name ablehnen?', ['name' => MemberProposal::authorNameOf($decisionCandidate->pleb)]) }}
                            @endif
                        </flux:heading>
                        <flux:text class="mt-2">
                            @if ($decisionCandidate)
                                {{ __('Wahlgang „:round“. Der Grund steht im Protokoll der Wahl.', ['round' => $decisionCandidate->round?->title]) }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:field>
                        <flux:label>{{ __('Grund') }}</flux:label>
                        <flux:textarea wire:model="decisionReason" rows="3" maxlength="1000" data-test="decision-reason"
                                       placeholder="{{ $decisionKind === 'withdraw' ? __('z. B. auf eigenen Wunsch, per Nachricht vom 3. März') : __('z. B. Kandidatur für ein Amt, das nicht zur Wahl steht') }}" />
                        <flux:error name="decisionReason" />
                    </flux:field>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <flux:modal.close>
                            <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="danger" class="min-h-11 w-full sm:w-auto" data-test="confirm-decision">
                            {{ $decisionKind === 'withdraw' ? __('Zurückziehen') : __('Ablehnen') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</div>
