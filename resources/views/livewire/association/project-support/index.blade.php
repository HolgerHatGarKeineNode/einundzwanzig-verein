<?php

use App\Enums\ProjectProposalStatus;
use App\Livewire\Traits\WithNostrAuth;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithNostrAuth;
    use WithPagination;

    /**
     * Erlaubte Sortierungen, abgebildet auf Spalte und Richtung. Die Whitelist
     * verhindert, dass beliebige Nutzereingaben in orderBy() landen.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SORT_OPTIONS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'supporters' => ['supporters_count', 'desc'],
        // 'sats' hat keine Einzelspalte: sortiert über displayedSats-Logik
        // (sats_paid wenn > 0, sonst support_in_sats) — siehe projects().
        'sats' => ['sats_paid', 'desc'],
    ];

    private const PER_PAGE = 12;

    /**
     * Pseudo-Filter neben den abgeleiteten Status: „mir fehlt hier noch die
     * Stimme". Kein Enum-Fall, weil er nicht am Antrag hängt, sondern am
     * Betrachter — und nur für den Vorstand überhaupt existiert.
     */
    private const FILTER_AWAITING_OWN_VOTE = 'awaiting_my_vote';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $activeFilter = 'all';

    #[Url(as: 'sort', except: 'newest')]
    public string $sortBy = 'newest';

    #[Locked]
    public ?ProjectProposal $projectToDelete = null;

    protected $listeners = [
        'confirmDeleteProject' => 'confirmDeleteProject',
    ];

    public function mount(): void
    {
        $this->mountWithNostrAuth();

        // Der Filter kommt auch aus der URL. Wer nicht im Vorstand ist, wird
        // hier zurückgesetzt — sonst genügte ein Link, um die Query zu fahren.
        if ($this->activeFilter === self::FILTER_AWAITING_OWN_VOTE && ! $this->isBoardMember) {
            $this->activeFilter = 'all';
        }
    }

    /**
     * Nur Vorstandsmitglieder haben eine Stimme, die fehlen kann.
     */
    #[Computed]
    public function isBoardMember(): bool
    {
        return (bool) $this->currentPleb?->isBoardMember();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Die gefilterte, sortierte und paginierte Liste. Die Stimm-Aggregate kommen
     * als Unterabfrage mit, damit weder Stimmen noch Profile je Karte nachgeladen
     * werden müssen.
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        [$column, $direction] = self::SORT_OPTIONS[$this->sortBy] ?? self::SORT_OPTIONS['newest'];

        $query = $this->filteredQuery()
            ->withVoteAggregates()
            ->withOwnVote($this->currentPleb?->id)
            ->with(['einundzwanzigPleb.profile', 'media']);

        // Sortierung „Höchste Fördersumme": dieselben Sats wie auf der Karte —
        // ausgezahlter Betrag, sobald erfasst, sonst die Beantragung. Reines
        // orderBy('support_in_sats') würde korrigierte Auszahlungen ignorieren.
        if ($this->sortBy === 'sats') {
            $query->orderByRaw('CASE WHEN sats_paid > 0 THEN sats_paid ELSE support_in_sats END DESC');
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * Trefferzahl je Filter — damit ein leerer Filter als leer erkennbar ist,
     * statt wie eine kaputte Seite auszusehen.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = ['all' => $this->searchedQuery()->count()];

        foreach (ProjectProposalStatus::cases() as $status) {
            $counts[$status->value] = $this->searchedQuery()
                ->withStatus($status->value)
                ->count();
        }

        if ($this->isBoardMember) {
            $counts[self::FILTER_AWAITING_OWN_VOTE] = $this->searchedQuery()
                ->awaitingVoteFrom($this->currentPleb->id)
                ->count();
        }

        return $counts;
    }

    public function confirmDeleteProject(int|string $id): void
    {
        $project = ProjectProposal::query()->findOrFail($id);

        // Auch der reine Dialog-Öffner wird geprüft: die Methode ist als
        // Livewire-Endpunkt direkt aufrufbar und würde sonst für beliebige IDs
        // verraten, ob ein Antrag existiert.
        Gate::forUser(NostrAuth::user())->authorize('delete', $project);

        $this->projectToDelete = $project;
        Flux::modal('delete-project')->show();
    }

    public function setFilter(string $filter): void
    {
        $allowed = array_merge(['all'], ProjectProposalStatus::values());

        if ($this->isBoardMember) {
            $allowed[] = self::FILTER_AWAITING_OWN_VOTE;
        }

        if (! in_array($filter, $allowed, true)) {
            return;
        }

        $this->activeFilter = $filter;
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        if (! array_key_exists($sort, self::SORT_OPTIONS)) {
            return;
        }

        $this->sortBy = $sort;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->activeFilter = 'all';
        $this->sortBy = 'newest';
        $this->resetPage();
    }

    public function delete(): void
    {
        if ($this->projectToDelete) {
            Gate::forUser(NostrAuth::user())->authorize('delete', $this->projectToDelete);

            $this->projectToDelete->delete();
            Flux::toast('Projektunterstützung gelöscht.');
            Flux::modals()->close();
            $this->projectToDelete = null;
            unset($this->projects, $this->statusCounts);
        }
    }

    /**
     * Der mobile Select schreibt activeFilter direkt — also gilt dieselbe
     * Prüfung wie in setFilter(), nur eben am Property-Hook.
     */
    public function updatedActiveFilter(string $value): void
    {
        if ($value === self::FILTER_AWAITING_OWN_VOTE && ! $this->isBoardMember) {
            $this->activeFilter = 'all';
        }

        $this->resetPage();
    }

    /**
     * Nur die Suche angewandt — Ausgangspunkt sowohl für die Liste als auch für
     * die Filter-Zähler, die bewusst NICHT vom aktiven Filter eingeschränkt sind.
     */
    private function searchedQuery(): Builder
    {
        return ProjectProposal::query()->search($this->search);
    }

    /**
     * Suche plus aktiver Filter. Der Vorstands-Filter wird hier nochmals gegen
     * die Mitgliedschaft geprüft, damit er auf keinem Weg — URL, Mobile-Select,
     * direkter Livewire-Aufruf — an der Autorisierung vorbeikommt.
     */
    private function filteredQuery(): Builder
    {
        if ($this->activeFilter !== self::FILTER_AWAITING_OWN_VOTE) {
            return $this->searchedQuery()->withStatus($this->activeFilter);
        }

        if (! $this->isBoardMember) {
            return $this->searchedQuery();
        }

        return $this->searchedQuery()->awaitingVoteFrom($this->currentPleb->id);
    }
};
?>


<div>
    <div class="w-full max-w-[1600px] mx-auto">

        {{-- Kopf --}}
        <div class="mb-5">
            <h1 class="text-2xl sm:text-[28px] font-bold text-text-primary tracking-tight leading-[1.15]">
                Projektunterstützungen
            </h1>
            <p class="mt-1 text-sm text-text-secondary">
                Anträge des Vereins auf Förderung — {{ $this->statusCounts['all'] }}
                {{ $this->statusCounts['all'] === 1 ? 'Antrag' : 'Anträge' }},
                {{ $this->statusCounts['new'] }} warten auf den Vorstand.
            </p>
        </div>

        {{-- Ein Kasten, eine Aufgabe: Suche, Sortierung, Einreichen, Status --}}
        <div class="rounded-xl border border-border-subtle bg-bg-surface divide-y divide-border-subtle mb-5">
            <div class="flex flex-col sm:flex-row gap-3 p-3">
                <flux:input
                    class="flex-1"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    placeholder="Suche nach Projekt oder Einreicher"
                />
                <flux:select wire:model.live="sortBy" class="sm:w-56">
                    <flux:select.option value="newest">Neueste zuerst</flux:select.option>
                    <flux:select.option value="oldest">Älteste zuerst</flux:select.option>
                    <flux:select.option value="sats">Höchste Fördersumme</flux:select.option>
                    <flux:select.option value="supporters">Meiste Unterstützer</flux:select.option>
                </flux:select>
                @if(Gate::forUser(NostrAuth::user())->allows('create', App\Models\ProjectProposal::class))
                    <flux:button :href="route('association.projectSupport.create')" icon="plus" variant="primary">
                        Projekt einreichen
                    </flux:button>
                @endif
            </div>

            <div class="p-3">
                {{-- Mobile: Select statt Chip-Reihe, damit nichts abgeschnitten wird --}}
                <div class="sm:hidden">
                    <flux:select wire:model.live="activeFilter">
                        <flux:select.option value="all">Alle ({{ $this->statusCounts['all'] }})</flux:select.option>
                        @foreach(\App\Enums\ProjectProposalStatus::cases() as $status)
                            <flux:select.option value="{{ $status->value }}">
                                {{ $status->label() }} ({{ $this->statusCounts[$status->value] }})
                            </flux:select.option>
                        @endforeach
                        @if($this->isBoardMember)
                            <flux:select.option value="awaiting_my_vote">
                                Fehlende Stimme ({{ $this->statusCounts['awaiting_my_vote'] }})
                            </flux:select.option>
                        @endif
                    </flux:select>
                </div>

                <div class="hidden sm:flex sm:flex-wrap sm:items-center gap-2">
                    <button type="button"
                            wire:click="setFilter('all')"
                            aria-pressed="{{ $activeFilter === 'all' ? 'true' : 'false' }}"
                            @class([
                                'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-[13px] font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                                'border-orange-500 bg-orange-500 text-[#17120A]' => $activeFilter === 'all',
                                'border-border-default bg-bg-elevated text-text-secondary hover:border-neutral-400 hover:text-text-primary' => $activeFilter !== 'all',
                            ])>
                        <span>Alle</span>
                        <span>{{ $this->statusCounts['all'] }}</span>
                    </button>

                    @foreach(\App\Enums\ProjectProposalStatus::cases() as $status)
                        @php($isActive = $activeFilter === $status->value)
                        <button type="button"
                                wire:click="setFilter('{{ $status->value }}')"
                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-[13px] font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                                    'border-orange-500 bg-orange-500 text-[#17120A]' => $isActive,
                                    'border-border-default bg-bg-elevated text-text-secondary hover:border-neutral-400 hover:text-text-primary' => ! $isActive,
                                ])>
                            <flux:icon :name="$status->icon()" variant="micro" aria-hidden="true"/>
                            <span>{{ $status->label() }}</span>
                            <span @class(['text-text-disabled' => $this->statusCounts[$status->value] === 0 && ! $isActive])>
                                {{ $this->statusCounts[$status->value] }}
                            </span>
                        </button>
                    @endforeach

                    {{-- Arbeitsliste des Vorstands: hier fehlt MEINE Stimme.
                         Bewusst als eigener Chip am Ende der Reihe — er filtert
                         nicht nach Status, sondern nach dem Betrachter. --}}
                    @if($this->isBoardMember)
                        @php($isActive = $activeFilter === 'awaiting_my_vote')
                        <button type="button"
                                wire:click="setFilter('awaiting_my_vote')"
                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-[13px] font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                                    'border-orange-500 bg-orange-500 text-[#17120A]' => $isActive,
                                    'border-orange-500/40 bg-bg-elevated text-orange-300 hover:border-orange-400 hover:text-orange-200' => ! $isActive,
                                ])>
                            <flux:icon name="hand-raised" variant="micro" aria-hidden="true"/>
                            <span>Fehlende Stimme</span>
                            <span @class(['text-text-disabled' => $this->statusCounts['awaiting_my_vote'] === 0 && ! $isActive])>
                                {{ $this->statusCounts['awaiting_my_vote'] }}
                            </span>
                        </button>
                    @endif

                    @if($activeFilter !== 'all' || $search !== '' || $sortBy !== 'newest')
                        <flux:spacer/>
                        <flux:button wire:click="resetFilters" variant="subtle" icon="x-mark" size="sm">
                            Filter zurücksetzen
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Ergebniszeile: sagt die Wahrheit, auch gefiltert --}}
        <div class="mb-4 text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
            @if($activeFilter === 'all' && $search === '')
                {{ $this->statusCounts['all'] }} {{ $this->statusCounts['all'] === 1 ? 'Projekt' : 'Projekte' }}
            @else
                {{ $this->projects->total() }} von {{ $this->statusCounts['all'] }}
                {{ $this->statusCounts['all'] === 1 ? 'Projekt' : 'Projekten' }}
                @if($activeFilter === 'awaiting_my_vote')
                    · Fehlende Stimme
                @elseif($activeFilter !== 'all')
                    · {{ \App\Enums\ProjectProposalStatus::from($activeFilter)->label() }}
                @endif
                @if($search !== '')
                    · Suche „{{ $search }}"
                @endif
            @endif
        </div>

        {{-- Liste --}}
        @if($this->projects->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-4 2xl:gap-5 mb-6">
                @foreach($this->projects as $project)
                    <x-project-card
                        :project="$project"
                        :currentPleb="$currentPleb"
                        :eager="$loop->index < 3"
                        wire:key="project-{{ $project->id }}"
                    />
                @endforeach
            </div>

            {{-- flux:pagination statt ->links(): letzteres rendert Laravels
                 Standard-View, die weder das Dark-Theme noch die Hausschrift kennt. --}}
            <div class="mb-8">
                <flux:pagination :paginator="$this->projects"/>
            </div>
        @else
            <div class="rounded-xl border border-border-subtle bg-bg-surface p-8 text-center mb-8">
                @if($search !== '')
                    <flux:icon name="magnifying-glass" class="mx-auto size-12 text-text-disabled" aria-hidden="true"/>
                    <p class="mt-4 text-base font-semibold text-text-primary">Nichts gefunden für „{{ $search }}".</p>
                    <p class="mt-1 text-sm text-text-secondary">
                        Die Suche prüft Projektname, Beschreibung und Einreicher.
                    </p>
                    <flux:button wire:click="resetFilters" variant="subtle" class="mt-4">Suche löschen</flux:button>
                @elseif($activeFilter === 'awaiting_my_vote')
                    <flux:icon name="check-circle" class="mx-auto size-12 text-text-disabled" aria-hidden="true"/>
                    <p class="mt-4 text-base font-semibold text-text-primary">
                        Du hast überall abgestimmt.
                    </p>
                    <p class="mt-1 text-sm text-text-secondary">
                        Kein Antrag in Abstimmung wartet noch auf deine Stimme.
                    </p>
                    <flux:button wire:click="resetFilters" variant="subtle" class="mt-4">Alle Projekte anzeigen</flux:button>
                @elseif($activeFilter !== 'all')
                    <flux:icon :name="\App\Enums\ProjectProposalStatus::from($activeFilter)->icon()"
                               class="mx-auto size-12 text-text-disabled" aria-hidden="true"/>
                    <p class="mt-4 text-base font-semibold text-text-primary">
                        Kein Projekt im Zustand „{{ \App\Enums\ProjectProposalStatus::from($activeFilter)->label() }}".
                    </p>
                    <p class="mt-1 text-sm text-text-secondary">
                        Die übrigen Anträge stehen unter einem anderen Filter.
                    </p>
                    <flux:button wire:click="resetFilters" variant="subtle" class="mt-4">Alle Projekte anzeigen</flux:button>
                @else
                    <flux:icon name="heart" class="mx-auto size-12 text-text-disabled" aria-hidden="true"/>
                    <p class="mt-4 text-base font-semibold text-text-primary">Noch kein Antrag eingereicht.</p>
                    <p class="mt-1 text-sm text-text-secondary">
                        Aktive Mitglieder können Projekte zur Förderung vorschlagen.
                    </p>
                    @if(Gate::forUser(NostrAuth::user())->allows('create', App\Models\ProjectProposal::class))
                        <flux:button :href="route('association.projectSupport.create')" variant="primary" icon="plus" class="mt-4">
                            Projekt einreichen
                        </flux:button>
                    @endif
                @endif
            </div>
        @endif

        {{-- Löschen bestätigen --}}
        <flux:modal name="delete-project" class="min-w-88">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Projektunterstützung löschen?</flux:heading>
                    <flux:text class="mt-2">
                        <p>Du bist dabei, diese Projektunterstützung zu löschen.</p>
                        <p>Diese Aktion kann nicht rückgängig gemacht werden.</p>
                    </flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:spacer/>
                    <flux:modal.close>
                        <flux:button variant="ghost">Abbrechen</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Löschen</flux:button>
                </div>
            </div>
        </flux:modal>
    </div>
</div>
