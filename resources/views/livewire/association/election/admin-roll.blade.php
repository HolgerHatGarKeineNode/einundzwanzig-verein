<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Livewire\Traits\WithViewer;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\EnrolByHand;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The electoral roll of an election on the board's election page: before
 * any round is opened who the fee rule entitles, after that the roll each
 * opened round wrote down. Counts and names only, never votes.
 *
 * A child of `association.election.admin`, and callable on its own like
 * every Livewire component: each public method asks the board guard itself.
 * After a member is enrolled or entitled by hand it tells the page
 * `election-changed`, so the run sheet follows.
 */
new class extends Component
{
    use WithViewer;

    #[Locked]
    public int $electionId;

    public ?int $rollRoundId = null;

    public ?int $enrolMemberId = null;

    public string $enrolReason = '';

    public ?int $entitleMemberId = null;

    public string $entitleReason = '';

    public function mount(int $electionId): void
    {
        $this->boardActor();

        $this->electionId = $electionId;
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
        unset($this->rollEntries, $this->enrolCandidates);
        $this->dispatch('election-changed');
        Flux::toast(__(':name ist im Verzeichnis von „:round“ nachgetragen.', ['name' => MemberProposal::authorNameOf($member), 'round' => $round->title]), variant: 'success');
    }

    /**
     * Record that a member is entitled to vote in the election
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

        $election = $this->election;
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
        unset($this->election, $this->rollPreview, $this->entitleCandidates);
        $this->dispatch('election-changed');
        Flux::toast(__(':name ist für „:election“ als wahlberechtigt eingetragen.', ['name' => $name, 'election' => $election->name]), variant: 'success');
    }

    #[Computed]
    public function election(): Election
    {
        $this->boardActor();

        return Election::query()->with('rounds')->findOrFail($this->electionId);
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

        $election = $this->election;
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
     * The opened rounds of the election — each has a roll of its own.
     *
     * @return Collection<int, ElectionRound>
     */
    #[Computed]
    public function openedRounds(): Collection
    {
        $this->boardActor();

        return $this->election->rounds->whereNotNull('opened_at')->values();
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
        $election = $this->election;

        if ($round === null) {
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
     * Active and honorary members the fee rule of the election does
     * not entitle right now — whom the board can enter as entitled by hand.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function entitleCandidates(): Collection
    {
        $this->boardActor();

        $election = $this->election;

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

    protected function shortDate(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : DisplayTime::of($moment)->format('d.m.Y');
    }

    protected function signedInSinceAnnouncement(Election $election, EinundzwanzigPleb $pleb): ?CarbonInterface
    {
        return $election->announced_at !== null && $pleb->last_login_at !== null && $pleb->last_login_at->gte($election->announced_at)
            ? $pleb->last_login_at
            : null;
    }
};
?>

@php
    $election = $this->election;
    $opened = $this->openedRounds;
    $announced = $election->announced_at !== null;
    $ruleLabel = $election->fee_rule->label($election->fee_year);
@endphp

<div class="relative" data-test="roll">
    @if ($opened->isEmpty())
        @php
            $rows = $this->rollPreview;
            $entitledCount = $rows->whereNotNull('reason')->count();
            $signedInCount = $rows->whereNotNull('reason')->whereNotNull('signed_in')->count();
        @endphp
        <div class="max-w-2xl space-y-2">
            <p class="text-lg text-text-primary" data-test="roll-summary">
                {{ trans_choice('{0} Noch niemand ist wahlberechtigt.|{1} :count von :total Aktiv- und Ehrenmitgliedern ist wahlberechtigt.|[2,*] :count von :total Aktiv- und Ehrenmitgliedern sind wahlberechtigt.', $entitledCount, ['count' => $entitledCount, 'total' => $rows->count()]) }}
                @if ($announced)
                    {{ trans_choice('{0} Davon hat sich seit der Ankündigung noch niemand angemeldet.|{1} Davon hat sich eine Person seit der Ankündigung angemeldet.|[2,*] Davon haben sich :count seit der Ankündigung angemeldet.', $signedInCount, ['count' => $signedInCount]) }}
                @endif
            </p>
            <p class="text-sm text-text-secondary">
                {{ __('Stand jetzt, nach der Regel „:rule“. Beim Öffnen jedes Wahlgangs wird das Verzeichnis festgeschrieben; wer danach bezahlt, kommt automatisch dazu.', ['rule' => $ruleLabel]) }}
            </p>
            @unless ($announced)
                <p class="text-sm text-text-secondary">{{ __('Ab der Ankündigung zeigt die Liste auch, wer sich seitdem mit Nostr angemeldet hat. Wer das nicht hat, braucht vor der Sitzung vielleicht Hilfe.') }}</p>
            @endunless
        </div>

        @if ($rows->isEmpty())
            <p class="mt-6 max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10 text-base text-text-secondary">{{ __('Es gibt keine Aktiv- oder Ehrenmitglieder.') }}</p>
        @else
            <ul class="mt-6 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface" data-test="roll-list">
                <li class="relative hidden gap-4 px-5 py-3 text-sm text-text-tertiary sm:grid {{ $announced ? 'sm:grid-cols-[minmax(0,1fr)_17rem_13rem]' : 'sm:grid-cols-[minmax(0,1fr)_17rem]' }}" aria-hidden="true">
                    <span>{{ __('Mitglied') }}</span>
                    <span>{{ __('Wahlberechtigt') }}</span>
                    @if ($announced)<span>{{ __('Seit Ankündigung angemeldet') }}</span>@endif
                </li>
                @foreach ($rows as $row)
                    <li wire:key="roll-preview-{{ $loop->index }}" class="relative grid gap-1 px-4 py-3 sm:gap-4 sm:px-5 {{ $announced ? 'sm:grid-cols-[minmax(0,1fr)_17rem_13rem]' : 'sm:grid-cols-[minmax(0,1fr)_17rem]' }}" data-test="roll-row">
                        <span class="min-w-0 font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$row['pubkey']" :name="$row['name']" :picture="$row['picture']" /></span>
                        <span class="text-sm sm:text-base">
                            <span class="text-text-tertiary sm:sr-only">{{ __('Wahlberechtigt:') }}</span>
                            @if ($row['reason'])
                                <span class="text-text-primary">{{ __('Ja') }}</span><span class="text-text-secondary">, {{ $row['reason']->label() }}</span>
                            @else
                                <span class="text-text-secondary">{{ __('Nein') }}, {{ $election->fee_rule->missingText($election->fee_year) }}</span>
                            @endif
                        </span>
                        @if ($announced)
                            <span class="text-sm sm:text-base">
                                <span class="text-text-tertiary sm:sr-only">{{ __('Seit Ankündigung angemeldet:') }}</span>
                                @if ($row['signed_in'])
                                    <span class="text-text-primary">{{ __('Ja, :date', ['date' => $this->shortDate($row['signed_in'])]) }}</span>
                                @elseif ($row['reason'])
                                    <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('Nein') }}</span>
                                @else
                                    <span class="text-text-secondary">{{ __('Nein') }}</span>
                                @endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        @php
            $round = $this->rollRound;
            $entries = $this->rollEntries;
            $takesEnrolment = in_array($round->stateAt(now()), [\App\Enums\ElectionRoundState::Scheduled, \App\Enums\ElectionRoundState::Open], true);
        @endphp

        @if ($opened->count() > 1)
            <div class="mb-6 max-w-sm [&_select]:min-h-11">
                <flux:select wire:model.live="rollRoundId" :label="__('Verzeichnis von')" data-test="roll-round">
                    @foreach ($opened as $openedRound)
                        <flux:select.option value="{{ $openedRound->id }}">{{ $openedRound->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="max-w-2xl space-y-2">
            <p class="text-lg text-text-primary" data-test="roll-summary">
                {{ trans_choice('{0} Das Verzeichnis von „:round“ ist leer.|{1} Im Verzeichnis von „:round“ steht eine Person.|[2,*] Im Verzeichnis von „:round“ stehen :count Personen.', $entries->count(), ['count' => $entries->count(), 'round' => $round->title]) }}
            </p>
            <p class="text-sm text-text-secondary">{{ __('Festgeschrieben beim Öffnen am :date. Niemand wird daraus entfernt.', ['date' => $this->shortDate($round->opened_at).', '.$round->opened_at->format('H:i')]) }}</p>
        </div>

        @if ($takesEnrolment)
            <form wire:submit="enrol" class="relative mt-6 max-w-2xl space-y-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6 [&_input]:min-h-11 [&_select]:min-h-11" data-test="enrol-form">
                <h3 class="text-lg font-semibold text-text-primary">{{ __('Mitglied von Hand nachtragen') }}</h3>
                <p class="text-base text-text-secondary">{{ __('Für einen Beitrag, den die Zahlung nicht kennt, etwa bar bezahlt. Der Grund steht im Protokoll der Wahl.') }}</p>
                <flux:select wire:model="enrolMemberId" :label="__('Mitglied')" :placeholder="__('Mitglied wählen')" data-test="enrol-member">
                    @foreach ($this->enrolCandidates as $candidate)
                        <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="enrolReason" :label="__('Grund')" maxlength="1000" placeholder="{{ __('z. B. Beitrag bar bezahlt am 3. März') }}" data-test="enrol-reason" />
                <flux:button type="submit" class="min-h-11 w-full sm:w-auto" data-test="enrol-submit">{{ __('Nachtragen') }}</flux:button>
            </form>
        @else
            <p class="mt-4 text-sm text-text-secondary">{{ __('Nachtragen von Hand geht nur, solange der Wahlgang offen ist.') }}</p>
        @endif

        <ul class="mt-6 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface" data-test="roll-list">
            <li class="relative hidden gap-4 px-5 py-3 text-sm text-text-tertiary sm:grid sm:grid-cols-[minmax(0,1fr)_17rem_13rem]" aria-hidden="true">
                <span>{{ __('Mitglied') }}</span>
                <span>{{ __('Grund') }}</span>
                <span>{{ __('Seit Ankündigung angemeldet') }}</span>
            </li>
            @foreach ($entries as $row)
                <li wire:key="roll-entry-{{ $loop->index }}" class="relative grid gap-1 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_17rem_13rem] sm:gap-4 sm:px-5" data-test="roll-row">
                    <span class="min-w-0 font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$row['pubkey']" :name="$row['name']" :picture="$row['picture']" /></span>
                    <span class="text-sm sm:text-base">
                        <span class="text-text-tertiary sm:sr-only">{{ __('Grund:') }}</span>
                        <span class="text-text-primary">{{ $row['reason']->label() }}</span><span class="text-text-secondary">, {{ $row['source']->label() }}</span>
                    </span>
                    <span class="text-sm sm:text-base">
                        <span class="text-text-tertiary sm:sr-only">{{ __('Seit Ankündigung angemeldet:') }}</span>
                        @if ($row['signed_in'])
                            <span class="text-text-primary">{{ __('Ja, :date', ['date' => $this->shortDate($row['signed_in'])]) }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('Nein') }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->takesEntitlement($election))
        <form wire:submit="entitle" class="relative mt-8 max-w-2xl space-y-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6 [&_input]:min-h-11 [&_select]:min-h-11" data-test="entitle-form">
            <h3 class="text-lg font-semibold text-text-primary">{{ __('Wahlberechtigung von Hand eintragen') }}</h3>
            <p class="text-base text-text-secondary">{{ __('Für ein Aktiv- oder Ehrenmitglied, dessen Beitrag die Zahlung nicht kennt, etwa bar bezahlt. Danach lässt sich seine Kandidatur freigeben, und jeder Wahlgang, den ihr danach öffnet, nimmt es ins Verzeichnis auf. Der Grund steht im Protokoll der Wahl.') }}</p>
            <flux:select wire:model="entitleMemberId" :label="__('Mitglied')" :placeholder="__('Mitglied wählen')" data-test="entitle-member">
                @foreach ($this->entitleCandidates as $candidate)
                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="entitleReason" :label="__('Grund')" maxlength="1000" placeholder="{{ __('z. B. Beitrag bar bezahlt am 3. März') }}" data-test="entitle-reason" />
            <flux:button type="submit" class="min-h-11 w-full sm:w-auto" data-test="entitle-submit">{{ __('Als wahlberechtigt eintragen') }}</flux:button>
        </form>
    @endif
</div>
