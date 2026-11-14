<?php

use App\Enums\MemberProposalState;
use App\Livewire\Traits\WithMemberProposalAccess;
use App\Models\MemberProposal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithMemberProposalAccess;
    use WithPagination;

    /**
     * The sort orders on offer. A whitelist, so nothing a client sends ever
     * reaches orderBy().
     */
    private const SORT_OPTIONS = ['support', 'newest', 'activity'];

    private const PER_PAGE = 20;

    #[Url(as: 'sort', except: 'support')]
    public string $sortBy = 'support';

    #[Url(as: 'state', except: 'all')]
    public string $stateFilter = 'all';

    /**
     * The archive is this page with the other half of the states. Decided by
     * the route and locked, so a client cannot flip it.
     */
    #[Locked]
    public bool $archive = false;

    public function mount(bool $archive = false): void
    {
        $this->archive = $archive;

        $this->normaliseFilters();
    }

    public function updatedSortBy(): void
    {
        $this->normaliseFilters();
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->normaliseFilters();
        $this->resetPage();
    }

    #[Computed]
    public function canView(): bool
    {
        return $this->viewerCan('viewAny');
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->viewerCan('create');
    }

    #[Computed]
    public function canModerate(): bool
    {
        return $this->viewerCan('moderate');
    }

    /**
     * The states this page can show: the current ones, or the archived ones.
     *
     * @return list<MemberProposalState>
     */
    #[Computed]
    public function states(): array
    {
        return $this->archive ? MemberProposalState::archived() : MemberProposalState::current();
    }

    /**
     * The filtered, sorted and paginated list. Votes arrive as two counts
     * from subqueries; no vote row is loaded.
     */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        $this->authorizeViewer('viewAny');

        $selected = MemberProposalState::tryFrom($this->stateFilter);

        $query = MemberProposal::query()
            ->visibleTo($this->viewer())
            ->inStates($selected !== null ? [$selected] : $this->states)
            ->withVoteCounts()
            ->withCount(['comments as visible_comments_count' => fn (Builder $query) => $query->where('is_hidden', false)])
            ->with('einundzwanzigPleb.profile');

        match ($this->sortBy) {
            'newest' => $query->orderByDesc('created_at'),
            'activity' => $query->orderByDesc('last_activity_at'),
            default => $query->orderByDesc('up_votes_count')->orderBy('down_votes_count'),
        };

        return $query->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    /**
     * Both come from the URL and from the selects, so both are checked here
     * rather than trusted: an unknown sort falls back to the default, and a
     * state that does not belong on this page falls back to "all".
     */
    private function normaliseFilters(): void
    {
        if (! in_array($this->sortBy, self::SORT_OPTIONS, true)) {
            $this->sortBy = 'support';
        }

        $selected = MemberProposalState::tryFrom($this->stateFilter);

        if ($selected === null || ! in_array($selected, $this->states, true)) {
            $this->stateFilter = 'all';
        }
    }
};
?>

<div>
    @if (! $this->canView)
        <x-proposal-members-only />
    @else
        <div class="w-full max-w-5xl mx-auto pb-20">
            {{-- Head: what this page is, and the one thing to do here. --}}
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0 max-w-2xl">
                    <h1 class="text-2xl sm:text-3xl font-bold leading-tight text-text-primary">
                        {{ $archive ? __('Archiv der Vorschläge') : __('Vorschläge') }}
                    </h1>
                    <p class="mt-2 text-base text-text-secondary">
                        @if ($archive)
                            {{ __('Beschlossene, abgelehnte und erledigte Vorschläge. Alles bleibt lesbar, Stimmen und Kommentare sind geschlossen.') }}
                        @else
                            {{ __('Vorschläge der Mitglieder an den Verein. Die Abstimmung ist ein Stimmungsbild, kein Beschluss.') }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 sm:shrink-0">
                    @if ($this->canModerate)
                        <flux:button :href="route('association.proposals.board')" icon="clipboard-document-list" class="min-h-11" wire:navigate>
                            {{ __('Für die Sitzung') }}
                        </flux:button>
                    @endif
                    @if ($this->canCreate && ! $archive)
                        <flux:button :href="route('association.proposals.create')" icon="plus" variant="primary" class="min-h-11" wire:navigate>
                            {{ __('Vorschlag einreichen') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Current and archive are two routes; the tabs say which one you are on. --}}
            <nav aria-label="{{ __('Vorschläge') }}" class="mb-4 flex gap-6 border-b border-border-default">
                @foreach ([
                    [false, route('association.proposals.index'), __('Aktuell')],
                    [true, route('association.proposals.archive'), __('Archiv')],
                ] as [$isArchiveTab, $href, $label])
                    <a href="{{ $href }}" wire:navigate
                       @if ($archive === $isArchiveTab) aria-current="page" @endif
                       @class([
                           '-mb-px inline-flex min-h-11 items-center border-b-2 px-1 text-base font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                           'border-orange-500 text-text-primary' => $archive === $isArchiveTab,
                           'border-transparent text-text-secondary hover:text-text-primary' => $archive !== $isArchiveTab,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="mb-4 grid grid-cols-1 gap-3 sm:flex sm:justify-end [&_select]:min-h-11">
                <flux:select wire:model.live="sortBy" class="sm:w-56" :label="__('Sortierung')">
                    <flux:select.option value="support">{{ __('Meiste Zustimmung') }}</flux:select.option>
                    <flux:select.option value="newest">{{ __('Neueste zuerst') }}</flux:select.option>
                    <flux:select.option value="activity">{{ __('Zuletzt aktiv') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="stateFilter" class="sm:w-56" :label="__('Zustand')">
                    <flux:select.option value="all">{{ __('Alle') }}</flux:select.option>
                    @foreach ($this->states as $state)
                        <flux:select.option value="{{ $state->value }}">{{ __($state->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->proposals->isEmpty())
                <div class="rounded-xl border border-dashed border-border-default px-6 py-12 text-center">
                    <p class="text-base text-text-secondary">
                        @if ($archive)
                            {{ __('Im Archiv liegt noch kein Vorschlag.') }}
                        @elseif ($stateFilter !== 'all')
                            {{ __('Kein Vorschlag in diesem Zustand.') }}
                        @else
                            {{ __('Hier gibt es noch keine Vorschläge.') }}
                        @endif
                    </p>
                    @if ($this->canCreate && ! $archive && $stateFilter === 'all')
                        <flux:button :href="route('association.proposals.create')" icon="plus" variant="primary" class="mt-4 min-h-11" wire:navigate>
                            {{ __('Ersten Vorschlag einreichen') }}
                        </flux:button>
                    @endif
                </div>
            @else
                {{-- One box, one row per proposal. The whole row is the link. --}}
                <ul class="divide-y divide-border-subtle overflow-hidden rounded-xl border border-border-subtle bg-bg-surface">
                    @foreach ($this->proposals as $proposal)
                        <li wire:key="proposal-{{ $proposal->id }}"
                            class="relative grid gap-3 px-4 py-4 transition-colors duration-150 motion-reduce:transition-none hover:bg-bg-elevated has-[a:focus-visible]:outline-2 has-[a:focus-visible]:-outline-offset-2 has-[a:focus-visible]:outline-orange-500 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-6 sm:px-5">
                            <div class="min-w-0">
                                <a href="{{ route('association.proposals.show', $proposal) }}" wire:navigate
                                   class="text-lg font-semibold leading-snug text-text-primary break-words outline-none after:absolute after:inset-0">
                                    {{ $proposal->title }}
                                </a>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-text-secondary">
                                    <flux:badge size="sm" :color="$proposal->state->color()" :icon="$proposal->state->icon()">
                                        {{ __($proposal->state->label()) }}
                                    </flux:badge>
                                    @if ($proposal->is_hidden)
                                        <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                                    @endif
                                    <span class="min-w-0 break-words">{{ __('von :name', ['name' => $proposal->authorName()]) }}</span>
                                    <time datetime="{{ $proposal->created_at->toDateString() }}">{{ $proposal->created_at->format('d.m.Y') }}</time>
                                </div>
                            </div>
                            <div class="flex items-end justify-between gap-4 sm:w-56 sm:flex-col sm:items-end">
                                <x-proposal-mood :up="$proposal->up_votes_count" :down="$proposal->down_votes_count" />
                                <span class="inline-flex shrink-0 items-center gap-1 text-sm text-text-secondary">
                                    <flux:icon name="chat-bubble-left" variant="micro" aria-hidden="true" />
                                    {{ trans_choice(':count Kommentar|:count Kommentare', $proposal->visible_comments_count, ['count' => $proposal->visible_comments_count]) }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">
                    {{ $this->proposals->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
