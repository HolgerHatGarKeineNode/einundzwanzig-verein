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
        <div class="w-full max-w-[1600px] mx-auto">
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold text-text-primary">
                        {{ $archive ? __('Archiv der Vorschläge') : __('Vorschläge') }}
                    </h1>
                    <p class="mt-1 text-sm text-text-secondary">
                        @if ($archive)
                            {{ __('Beschlossene, abgelehnte und erledigte Vorschläge. Alles bleibt lesbar, Stimmen und Kommentare sind geschlossen.') }}
                        @else
                            {{ __('Vorschläge der Mitglieder an den Verein. Die Abstimmung ist ein Stimmungsbild, kein Beschluss.') }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($archive)
                        <flux:button :href="route('association.proposals.index')" icon="arrow-left" wire:navigate>
                            {{ __('Aktuelle Vorschläge') }}
                        </flux:button>
                    @else
                        <flux:button :href="route('association.proposals.archive')" icon="archive-box" wire:navigate>
                            {{ __('Archiv') }}
                        </flux:button>
                    @endif
                    @if ($this->canModerate)
                        <flux:button :href="route('association.proposals.board')" icon="clipboard-document-list" wire:navigate>
                            {{ __('Für die Sitzung') }}
                        </flux:button>
                    @endif
                    @if ($this->canCreate && ! $archive)
                        <flux:button :href="route('association.proposals.create')" icon="plus" variant="primary" wire:navigate>
                            {{ __('Vorschlag einreichen') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <div class="mb-5 flex flex-col gap-3 sm:flex-row">
                <flux:select wire:model.live="sortBy" class="sm:w-64" :label="__('Sortierung')">
                    <flux:select.option value="support">{{ __('Meiste Zustimmung') }}</flux:select.option>
                    <flux:select.option value="newest">{{ __('Neueste zuerst') }}</flux:select.option>
                    <flux:select.option value="activity">{{ __('Zuletzt aktiv') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="stateFilter" class="sm:w-64" :label="__('Zustand')">
                    <flux:select.option value="all">{{ __('Alle') }}</flux:select.option>
                    @foreach ($this->states as $state)
                        <flux:select.option value="{{ $state->value }}">{{ __($state->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->proposals->isEmpty())
                <flux:callout icon="information-circle">
                    <flux:callout.text>{{ __('Hier gibt es noch keine Vorschläge.') }}</flux:callout.text>
                </flux:callout>
            @else
                <ul class="space-y-3">
                    @foreach ($this->proposals as $proposal)
                        <li wire:key="proposal-{{ $proposal->id }}"
                            class="rounded-xl border border-border-subtle bg-bg-surface p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <a href="{{ route('association.proposals.show', $proposal) }}" wire:navigate
                                       class="text-lg font-semibold text-text-primary break-words hover:underline">
                                        {{ $proposal->title }}
                                    </a>
                                    <p class="mt-1 text-sm text-text-secondary break-words">
                                        {{ __('von :name', ['name' => $proposal->authorName()]) }}
                                        · {{ $proposal->created_at->format('d.m.Y') }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" :color="$proposal->state->color()" :icon="$proposal->state->icon()">
                                            {{ __($proposal->state->label()) }}
                                        </flux:badge>
                                        @if ($proposal->is_hidden)
                                            <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                                <dl class="flex shrink-0 gap-4 text-sm text-text-secondary">
                                    <div>
                                        <dt class="sr-only">{{ __('Dafür') }}</dt>
                                        <dd class="inline-flex items-center gap-1" title="{{ __('Dafür') }}">
                                            <flux:icon name="hand-thumb-up" variant="micro" aria-hidden="true" />
                                            <span data-test="up-count">{{ $proposal->up_votes_count }}</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="sr-only">{{ __('Dagegen') }}</dt>
                                        <dd class="inline-flex items-center gap-1" title="{{ __('Dagegen') }}">
                                            <flux:icon name="hand-thumb-down" variant="micro" aria-hidden="true" />
                                            <span data-test="down-count">{{ $proposal->down_votes_count }}</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="sr-only">{{ __('Kommentare') }}</dt>
                                        <dd class="inline-flex items-center gap-1" title="{{ __('Kommentare') }}">
                                            <flux:icon name="chat-bubble-left" variant="micro" aria-hidden="true" />
                                            <span>{{ $proposal->visible_comments_count }}</span>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-5">
                    {{ $this->proposals->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
