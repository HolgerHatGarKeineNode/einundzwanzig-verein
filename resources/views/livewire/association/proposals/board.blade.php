<?php

use App\Enums\MemberProposalState;
use App\Livewire\Traits\ModeratesMemberProposals;
use App\Livewire\Traits\WithMemberProposalAccess;
use App\Models\MemberProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The board's working list for a meeting: what is scheduled, and which open
 * proposals the members support most.
 *
 * Every public method of this component is a board action and authorises
 * itself — `$isAllowed` only decides what is rendered.
 */
new class extends Component
{
    use ModeratesMemberProposals;
    use WithMemberProposalAccess;

    private const TOP_SUPPORTED = 10;

    #[Locked]
    public bool $isAllowed = false;

    public function mount(): void
    {
        $this->isAllowed = $this->viewerCan('moderate');
    }

    /**
     * Scheduled proposals, in the order they were put on the agenda.
     *
     * @return Collection<int, MemberProposal>
     */
    #[Computed]
    public function scheduled(): Collection
    {
        $this->authorizeViewer('moderate');

        return $this->listQuery()
            ->inStates([MemberProposalState::Scheduled])
            ->orderBy('state_changed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The open proposals with the most support — candidates for the agenda.
     *
     * @return Collection<int, MemberProposal>
     */
    #[Computed]
    public function topSupported(): Collection
    {
        $this->authorizeViewer('moderate');

        return $this->listQuery()
            ->inStates([MemberProposalState::Open])
            ->orderByDesc('up_votes_count')
            ->orderBy('down_votes_count')
            ->orderByDesc('id')
            ->limit(self::TOP_SUPPORTED)
            ->get();
    }

    private function listQuery(): Builder
    {
        return MemberProposal::query()
            ->notWithdrawn()
            ->withVoteCounts()
            ->with('einundzwanzigPleb.profile');
    }

    protected function memberProposalsChanged(): void
    {
        unset($this->scheduled, $this->topSupported);
    }
};
?>

<div>
    @if (! $isAllowed)
        <div class="w-full max-w-[1600px] mx-auto">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Diese Ansicht ist dem Vorstand vorbehalten') }}</flux:callout.heading>
            </flux:callout>
        </div>
    @else
        <div class="w-full max-w-3xl mx-auto space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-2xl font-bold text-text-primary">{{ __('Vorschläge für die Sitzung') }}</h1>
                <flux:button :href="route('association.proposals.index')" variant="ghost" icon="arrow-left" wire:navigate>
                    {{ __('Vorschläge') }}
                </flux:button>
            </div>

            @foreach ([
                'scheduled' => [__('Vorgemerkt'), $this->scheduled, __('Nichts vorgemerkt.')],
                'top' => [__('Meistunterstützte offene Vorschläge'), $this->topSupported, __('Keine offenen Vorschläge.')],
            ] as $section => [$heading, $proposals, $empty])
                <section wire:key="section-{{ $section }}" data-test="section-{{ $section }}" class="space-y-3">
                    <flux:heading level="2" size="lg">{{ $heading }}</flux:heading>

                    @forelse ($proposals as $proposal)
                        <flux:card wire:key="{{ $section }}-{{ $proposal->id }}" class="space-y-3">
                            <div class="min-w-0">
                                <a href="{{ route('association.proposals.show', $proposal) }}" wire:navigate
                                   class="text-lg font-semibold text-text-primary break-words hover:underline">
                                    {{ $proposal->title }}
                                </a>
                                <p class="mt-1 text-sm text-text-secondary break-words">
                                    {{ __('von :name', ['name' => $proposal->authorName()]) }}
                                    · {{ __('Dafür') }} {{ $proposal->up_votes_count }}
                                    · {{ __('Dagegen') }} {{ $proposal->down_votes_count }}
                                    @if ($proposal->is_hidden)
                                        · {{ __('Ausgeblendet') }}
                                    @endif
                                </p>
                                @if (filled($proposal->board_note))
                                    <p class="mt-1 text-sm text-text-primary break-words whitespace-pre-line">{{ __('Notiz') }}: {{ $proposal->board_note }}</p>
                                @endif
                            </div>

                            <flux:field>
                                <flux:label>{{ __('Notiz') }}</flux:label>
                                <flux:input wire:model="boardNotes.{{ $proposal->id }}" maxlength="1000"
                                            placeholder="{{ __('z. B. Datum der Sitzung') }}" />
                                <flux:error name="boardNotes.{{ $proposal->id }}" />
                            </flux:field>

                            <div class="flex flex-wrap gap-2">
                                @foreach (MemberProposalState::cases() as $state)
                                    @if ($state !== $proposal->state)
                                        <flux:button wire:click="setProposalState({{ $proposal->id }}, '{{ $state->value }}')"
                                                     wire:key="{{ $section }}-{{ $proposal->id }}-{{ $state->value }}"
                                                     :icon="$state->icon()">
                                            {{ __($state->label()) }}
                                        </flux:button>
                                    @endif
                                @endforeach
                                <flux:button wire:click="saveBoardNote({{ $proposal->id }})"
                                             wire:key="{{ $section }}-{{ $proposal->id }}-note" variant="ghost">
                                    {{ __('Notiz speichern') }}
                                </flux:button>
                            </div>
                        </flux:card>
                    @empty
                        <p class="text-sm text-text-secondary">{{ $empty }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>
    @endif
</div>
