<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\Candidacies;
use App\Services\Election\ElectionSetup;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\EnrolByHand;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
    private const SECTIONS = ['settings', 'candidacies', 'roll'];

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
            'meeting_at' => filled($data['meeting_date'])
                ? Carbon::createFromFormat('Y-m-d H:i', $data['meeting_date'].' '.($data['meeting_time'] ?: '19:00'))
                : null,
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
                __(':name ist nach der Beitragsregel dieser Wahl noch nicht wahlberechtigt. Freigeben geht nach der Zahlung.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]),
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
            [__('Sitzung live'), $election->meeting_at === null ? __('Termin fehlt') : $this->shortDate($election->meeting_at).', '.$election->meeting_at->format('H:i')],
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
     * The one next step of an election: what to do, why, and the action that
     * does it — `null` when the step lies outside this page.
     *
     * @return array{text: string, hint: string|null, action: string|null, label: string|null}
     */
    protected function nextStepOf(Election $election): array
    {
        $rounds = $election->rounds->whereNull('parent_round_id');
        $phase = $this->phaseOf($election);

        if ($phase === 0) {
            if ($rounds->isEmpty()) {
                return ['text' => __('Lege mindestens einen Wahlgang an.'), 'hint' => __('Ohne Wahlgang lässt sich die Wahl nicht ankündigen.'), 'action' => 'settings', 'label' => __('Wahlgang anlegen')];
            }

            if ($election->meeting_at === null) {
                return ['text' => __('Trage den Sitzungstermin ein.'), 'hint' => __('Gewählt wird live in dieser Sitzung. Mitglieder sehen den Termin mit der Ankündigung.'), 'action' => 'settings', 'label' => __('Termin eintragen')];
            }

            return [
                'text' => __('Wahl ankündigen.'),
                'hint' => $election->is_test
                    ? __('Probewahl: auch angekündigt sieht sie nur der Vorstand.')
                    : __('Danach sehen alle Mitglieder die Wahl und können kandidieren.'),
                'action' => 'announce',
                'label' => __('Wahl ankündigen'),
            ];
        }

        if ($phase === 2) {
            $pending = $rounds->sum(fn (ElectionRound $round): int => $round->candidates->where('status', ElectionCandidateStatus::Pending)->count());

            if ($pending > 0) {
                return [
                    'text' => trans_choice('{1} Eine Kandidatur wartet auf Prüfung.|[2,*] :count Kandidaturen warten auf Prüfung.', $pending, ['count' => $pending]),
                    'hint' => __('Freigeben geht, sobald die Person nach der Beitragsregel wahlberechtigt ist.'),
                    'action' => 'candidacies',
                    'label' => __('Kandidaturen prüfen'),
                ];
            }

            $without = $rounds->first(fn (ElectionRound $round): bool => $round->candidates->where('status', ElectionCandidateStatus::Approved)->isEmpty());

            if ($without !== null) {
                return [
                    'text' => __('„:round“ hat noch keine freigegebene Kandidatur.', ['round' => $without->title]),
                    'hint' => __('Ohne freigegebene Kandidatur lässt sich ein Wahlgang in der Sitzung nicht öffnen.'),
                    'action' => 'candidacies',
                    'label' => __('Kandidaturen ansehen'),
                ];
            }

            return [
                'text' => $election->meeting_at === null
                    ? __('Bereit für die Sitzung.')
                    : __('Bereit für die Sitzung am :date.', ['date' => $this->longDate($election->meeting_at)]),
                'hint' => __('In der Sitzung öffnet ihr die Wahlgänge der Reihe nach.'),
                'action' => null,
                'label' => null,
            ];
        }

        if ($phase === 3) {
            $open = $rounds->first(fn (ElectionRound $round): bool => $round->stateAt(now()) === ElectionRoundState::Open);
            $next = $rounds->first(fn (ElectionRound $round): bool => $round->opened_at === null);

            return [
                'text' => match (true) {
                    $open !== null => __('„:round“ ist offen.', ['round' => $open->title]),
                    $next !== null => __('Als Nächstes: „:round“.', ['round' => $next->title]),
                    default => __('Die Sitzung läuft.'),
                },
                'hint' => __('Gewählt wird live in der Sitzung.'),
                'action' => null,
                'label' => null,
            ];
        }

        return ['text' => __('Alle Wahlgänge sind abgeschlossen.'), 'hint' => null, 'action' => null, 'label' => null];
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

    protected function shortDate(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $moment->format('d.m.Y');
    }

    protected function longDate(CarbonInterface $moment): string
    {
        return $moment->copy()->locale('de')->isoFormat('dd, D. MMMM YYYY').', '.$moment->format('H:i').' '.__('Uhr');
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
        unset($this->selectedElection, $this->candidaciesByRound, $this->rollPreview, $this->openedRounds, $this->rollRound, $this->rollEntries, $this->enrolCandidates);
        $this->rollRoundId = null;
        $this->form = $this->formOf($this->selectedElection ?? $election);
        $this->section = $this->nextStepOf($this->selectedElection ?? $election)['action'] === 'candidacies' ? 'candidacies' : 'settings';
    }

    protected function refreshElection(Election $election): void
    {
        unset($this->elections, $this->selectedElection, $this->candidaciesByRound, $this->rollPreview, $this->openedRounds);
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
            'meeting_date' => $election->meeting_at?->format('Y-m-d') ?? '',
            'meeting_time' => $election->meeting_at?->format('H:i') ?? '19:00',
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

<div class="relative w-full max-w-5xl mx-auto pb-20">
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
                        @if ($step['action'] !== null)
                            @php
                                $primary = $isSelected && ! $creating;
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
                    </div>

                    @if ($isSelected)
                        @php
                            $pendingCount = $item->rounds->sum(fn ($round) => $round->candidates->where('status', \App\Enums\ElectionCandidateStatus::Pending)->count());
                            $tabs = [
                                'settings' => [__('Einstellungen'), null],
                                'candidacies' => [__('Kandidaturen'), $pendingCount > 0 ? __(':count offen', ['count' => $pendingCount]) : null],
                                'roll' => [__('Wählerverzeichnis'), null],
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
