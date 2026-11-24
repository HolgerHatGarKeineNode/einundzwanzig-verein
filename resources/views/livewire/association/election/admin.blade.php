<?php

use App\Enums\ElectionPhase;
use App\Exceptions\ElectionActionRefusedException;
use App\Livewire\Traits\WithViewer;
use App\Models\Election;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\PurgeTestElection;
use App\Services\Election\RunSheet;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The board runs the election here: a run sheet per election with the one
 * next step, and for the selected election four tabs, each a child
 * component of its own — the settings (`admin-form`), the candidacies
 * (`admin-candidacies`), the electoral roll (`admin-roll`) and the meeting
 * (`admin-live`, mounted on every tab for the dialogs of the run sheet).
 * The children tell the page `election-changed` after an act, and the page
 * reads the run sheet again.
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

    #[Locked]
    public bool $isGuest = false;

    #[Locked]
    public ?int $selectedElectionId = null;

    #[Locked]
    public bool $creating = false;

    public string $section = 'settings';

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

    #[On('show-section')]
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
     * A child component acted, another board member or the turnout poll saw
     * a round change: read the run sheet again.
     */
    #[On('election-round-changed')]
    #[On('election-changed')]
    public function refreshRounds(): void
    {
        $this->boardActor();

        unset($this->elections, $this->selectedElection);
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
     * A stored moment in the zone the association meets in.
     */
    protected function local(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(config('app.display_timezone'));
    }

    protected function longDate(CarbonInterface $moment): string
    {
        $local = $this->local($moment);

        return $local->locale('de')->isoFormat('dd, D. MMMM YYYY').', '.$local->format('H:i').' '.__('Uhr');
    }

    protected function requireSelected(): Election
    {
        return Election::query()->findOrFail($this->selectedElectionId ?? abort(404));
    }

    protected function select(Election $election): void
    {
        $this->resetErrorBag();
        $this->selectedElectionId = (int) $election->id;
        unset($this->selectedElection);
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
        unset($this->elections, $this->selectedElection);
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
                            {{-- The tabs stay mounted, hidden, so what is being typed into one outlives a tab switch. --}}
                            @if ($creating)
                                @if ($section === 'settings')
                                    <p class="text-base text-text-secondary">{{ __('Speichert zuerst die neue Wahl oder brecht sie ab.') }}</p>
                                @endif
                            @else
                                <livewire:association.election.admin-form :election-id="$item->id" :visible="$section === 'settings'" :key="'settings-'.$item->id.($item->announced_at === null ? '' : '-announced')" />
                            @endif
                            <livewire:association.election.admin-candidacies :election-id="$item->id" :visible="$section === 'candidacies'" :key="'candidacies-'.$item->id" />
                            <livewire:association.election.admin-roll :election-id="$item->id" :visible="$section === 'roll'" :key="'roll-'.$item->id" />
                            {{-- On every tab: the run sheet takes its round steps through its dialogs. --}}
                            <livewire:association.election.admin-live :election-id="$item->id" :visible="$section === 'live'" :key="'live-'.$item->id" />
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
