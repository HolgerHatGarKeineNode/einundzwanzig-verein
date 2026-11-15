<?php

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRoundState;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\Candidacies;
use App\Services\Election\ElectoralRoll;
use App\Support\NostrAuth;
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
     * The chosen election, else the newest one that is not over, else the
     * newest one.
     */
    #[Computed]
    public function election(): ?Election
    {
        $elections = $this->elections;

        return $elections->firstWhere('id', $this->electionId)
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

        if ($this->ineligibleBecause === 'fee') {
            return 'pay';
        }

        return $this->openForCandidacy->isNotEmpty() ? 'candidacy' : null;
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

    protected function clearCaches(): void
    {
        unset($this->rounds, $this->ownCandidacies, $this->withdrawableIds, $this->openForCandidacy, $this->primaryAction, $this->phase);
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
     at the bottom of the viewport; the last line must scroll clear of it. --}}
<div class="mx-auto w-full max-w-5xl pb-32">
    @if (! $this->isMember)
        <div class="mx-auto max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Die Wahl ist nur für Mitglieder sichtbar') }}</flux:callout.heading>
                <flux:callout.text>
                    @if (! \App\Support\NostrAuth::check())
                        {{ __('Bitte melde dich zunächst mit Nostr an.') }}
                    @else
                        {{ __('Zu deinem Nostr-Konto gibt es noch keine Mitgliedschaft.') }}
                    @endif
                </flux:callout.text>
                @if (\App\Support\NostrAuth::check())
                    <x-slot name="actions">
                        <flux:button :href="route('association.profile')" class="min-h-11" wire:navigate>{{ __('Meine Mitgliedschaft') }}</flux:button>
                    </x-slot>
                @endif
            </flux:callout>
        </div>
    @else
        <header class="mb-8 max-w-2xl">
            <h1 class="text-3xl font-bold leading-tight text-text-primary sm:text-4xl">
                {{ $this->election?->name ?? __('Wahl') }}
            </h1>
            <p class="mt-2 text-base leading-normal text-text-secondary">
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
                               'inline-flex min-h-11 items-center gap-2 rounded-md border px-3 text-sm',
                               $focus,
                               'border-orange-500 text-text-primary' => $choice->is($this->election),
                               'border-border-default text-text-secondary hover:text-text-primary' => ! $choice->is($this->election),
                           ])>
                            @if ($choice->is_test)
                                <flux:icon.beaker variant="micro" aria-hidden="true" />
                            @endif
                            {{ $choice->name }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </header>

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
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <section class="rounded-lg border border-dashed border-border-default px-6 py-10" aria-labelledby="no-election">
                    <h2 id="no-election" class="text-xl font-bold text-text-primary">{{ __('Gerade steht keine Wahl an') }}</h2>
                    <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                        {{ __('Sobald der Vorstand eine Wahl ankündigt, stehen hier der Ablauf, die Kandidierenden und ob du wählen darfst.') }}
                    </p>
                </section>
                <x-election-signer-check :pubkey="$this->viewerPubkey" />
            </div>
        @else
            <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                {{-- Your standing: first on a phone, the right column on a desk. --}}
                <aside class="space-y-6 lg:col-start-2 lg:row-start-1" aria-label="{{ __('Dein Stand') }}">
                    <section aria-labelledby="eligibility" @class([
                        'rounded-lg border p-5',
                        'border-success/40 bg-bg-surface' => $this->isEntitled,
                        'border-orange-700 bg-bg-surface' => ! $this->isEntitled,
                    ])>
                        <h2 id="eligibility" class="text-lg font-bold text-text-primary">{{ __('Deine Wahlberechtigung') }}</h2>

                        @if ($this->isEntitled)
                            <p class="mt-3 flex items-start gap-2 text-base text-text-primary">
                                <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
                                <span>{{ __('Du darfst wählen.') }}</span>
                            </p>
                            <p class="mt-2 text-sm leading-normal text-text-secondary">
                                {{ __('Wählen darf, wer aktives Mitglied oder Ehrenmitglied ist und :rule hat.', ['rule' => $this->feeRuleText]) }}
                            </p>
                        @else
                            <p class="mt-3 flex items-start gap-2 text-base text-text-primary">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-orange-500" aria-hidden="true" />
                                <span>{{ __('Du darfst noch nicht wählen.') }}</span>
                            </p>
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

                    <x-election-signer-check :pubkey="$this->viewerPubkey" />
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
                                                    {{ __('Angekündigt am :date', ['date' => $this->election->announced_at->timezone(config('app.timezone'))->format('d.m.Y')]) }}
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
                                                                    {{ __('Du willst mitgestalten? Reich hier deine Kandidatur ein. Der Vorstand gibt sie frei, dann sehen alle Mitglieder deine Vorstellung.') }}
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
                                            @php $opensAt = $this->rounds->pluck('opens_at')->filter()->sort()->first(); @endphp
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if ($opensAt)
                                                    {{ __('Am :date um :time Uhr', ['date' => $opensAt->timezone(config('app.timezone'))->format('d.m.Y'), 'time' => $opensAt->timezone(config('app.timezone'))->format('H:i')]) }}
                                                @else
                                                    {{ __('Termin und Link zum Call kommen vom Vorstand') }}
                                                @endif
                                            </p>
                                            @if ($isCurrent)
                                                <p class="mt-3 max-w-prose text-base leading-normal text-text-primary">
                                                    {{ __('Der Vorstand öffnet die Wahlgänge nacheinander im Call. Der Stimmzettel erscheint dann hier auf dieser Seite.') }}
                                                </p>
                                                <ul class="mt-4 divide-y divide-border-subtle rounded-lg border border-border-subtle bg-bg-surface">
                                                    @foreach ($this->rounds as $round)
                                                        @php $state = $round->stateAt(now()); @endphp
                                                        <li wire:key="state-{{ $round->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                                            <span class="text-base text-text-primary">{{ $round->title }}</span>
                                                            <flux:badge size="sm"
                                                                        :color="match ($state) { ElectionRoundState::Open => 'green', ElectionRoundState::Closed, ElectionRoundState::Annulled => 'zinc', default => 'yellow' }"
                                                                        :icon="match ($state) { ElectionRoundState::Open => 'play', ElectionRoundState::Closed => 'lock-closed', ElectionRoundState::Annulled => 'x-mark', default => 'clock' }">
                                                                {{ match ($state) { ElectionRoundState::Open => __('Offen'), ElectionRoundState::Closed => __('Geschlossen'), ElectionRoundState::Annulled => __('Aufgehoben'), default => __('Wartet') } }}
                                                            </flux:badge>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @break

                                        @case('result')
                                            <p class="mt-1 text-sm text-text-secondary">
                                                @if ($isCurrent)
                                                    {{ __('Alle Wahlgänge sind geschlossen.') }}
                                                @else
                                                    {{ __('Direkt nach dem letzten Wahlgang') }}
                                                @endif
                                            </p>
                                            @break
                                    @endswitch
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
