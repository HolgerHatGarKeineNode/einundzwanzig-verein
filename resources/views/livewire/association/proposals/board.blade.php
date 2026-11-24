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

<div class="mx-auto w-full max-w-5xl pb-32">
    @if (! $isAllowed)
        <div class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Diese Ansicht ist dem Vorstand vorbehalten') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Die Vorschläge selbst findest du in der Liste.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button :href="route('association.proposals.index')" class="min-h-11" wire:navigate>{{ __('Zu den Vorschlägen') }}</flux:button>
                </x-slot>
            </flux:callout>
        </div>
    @else
        <div>
            <flux:button :href="route('association.proposals.index')" variant="ghost" icon="arrow-left" inset="left" class="mb-4 min-h-11" wire:navigate>
                {{ __('Vorschläge') }}
            </flux:button>

            <x-page-header>
                <x-slot:title>{{ __('Vorschläge für die Sitzung') }}</x-slot:title>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    {{ __('Oben steht, was vorgemerkt ist, in der Reihenfolge der Vormerkung. Darunter die offenen Vorschläge mit der meisten Zustimmung. Eine Notiz wird mit dem nächsten Zustand gespeichert oder einzeln mit „Notiz speichern“.') }}
                </p>
            </x-page-header>

            <div class="space-y-10">
                @foreach ([
                    'scheduled' => [__('Vorgemerkt'), $this->scheduled, __('Nichts vorgemerkt.')],
                    'top' => [__('Meistunterstützte offene Vorschläge'), $this->topSupported, __('Keine offenen Vorschläge.')],
                ] as $section => [$heading, $proposals, $empty])
                    <section wire:key="section-{{ $section }}" data-test="section-{{ $section }}" aria-labelledby="heading-{{ $section }}">
                        <h2 id="heading-{{ $section }}" class="mb-3 text-xl font-semibold text-text-primary">
                            {{ $heading }}
                            <span class="font-normal text-text-tertiary">{{ $proposals->count() }}</span>
                        </h2>

                        @if ($proposals->isEmpty())
                            <p class="rounded-xl border border-dashed border-border-default px-5 py-6 text-base text-text-secondary">{{ $empty }}</p>
                        @else
                            {{-- Ordered on purpose: agenda order above, rank by support below. --}}
                            <ol class="divide-y divide-border-default rounded-xl border border-border-default bg-bg-surface">
                                @foreach ($proposals as $proposal)
                                    <li wire:key="{{ $section }}-{{ $proposal->id }}"
                                        class="relative grid gap-4 p-4 sm:grid-cols-[2rem_minmax(0,1fr)] sm:p-5 lg:grid-cols-[2rem_minmax(0,1fr)_20rem] lg:gap-6">
                                        <span class="hidden text-xl font-bold text-text-tertiary sm:block" aria-hidden="true">{{ $loop->iteration }}</span>

                                        <div class="min-w-0">
                                            <a href="{{ route('association.proposals.show', $proposal) }}" wire:navigate
                                               class="-my-2.5 block py-2.5 text-lg font-semibold leading-snug text-text-primary break-words underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                                <span class="sm:hidden">{{ $loop->iteration }}.</span>
                                                {{ $proposal->title }}
                                            </a>
                                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-text-secondary">
                                                <span class="min-w-0 break-words">{{ __('von :name', ['name' => $proposal->authorName()]) }}</span>
                                                @if ($proposal->is_hidden)
                                                    <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                                                @endif
                                            </div>
                                            <x-proposal-mood :up="$proposal->up_votes_count" :down="$proposal->down_votes_count" class="mt-3" />
                                            @if (filled($proposal->board_note))
                                                <p class="mt-3 border-l-4 border-orange-500 pl-3 text-sm text-text-primary break-words whitespace-pre-line"><span class="font-semibold text-orange-500">{{ __('Notiz') }}:</span> {{ $proposal->board_note }}</p>
                                            @endif
                                        </div>

                                        <div class="space-y-2 sm:col-start-2 lg:col-start-3 [&_input]:min-h-11">
                                            <flux:field>
                                                <flux:label class="sr-only">{{ __('Notiz zu „:title“', ['title' => $proposal->title]) }}</flux:label>
                                                <flux:input wire:model="boardNotes.{{ $proposal->id }}" maxlength="1000"
                                                            placeholder="{{ filled($proposal->board_note) ? __('Neue Notiz') : __('Notiz, z. B. Datum der Sitzung') }}" />
                                                <flux:error name="boardNotes.{{ $proposal->id }}" />
                                            </flux:field>
                                            <div class="grid grid-cols-2 gap-2">
                                                <flux:button wire:click="saveBoardNote({{ $proposal->id }})"
                                                             wire:key="{{ $section }}-{{ $proposal->id }}-note" variant="ghost" class="min-h-11 w-full">
                                                    {{ __('Notiz speichern') }}
                                                </flux:button>
                                                <flux:dropdown position="bottom" align="end">
                                                    <flux:button icon:trailing="chevron-down" class="min-h-11 w-full">{{ __('Zustand ändern') }}</flux:button>
                                                    <flux:menu>
                                                        @foreach (MemberProposalState::cases() as $state)
                                                            @if ($state !== $proposal->state)
                                                                <flux:menu.item class="min-h-11" wire:click="setProposalState({{ $proposal->id }}, '{{ $state->value }}')"
                                                                                wire:key="{{ $section }}-{{ $proposal->id }}-{{ $state->value }}"
                                                                                :icon="$state->icon()">
                                                                    {{ __($state->label()) }}
                                                                </flux:menu.item>
                                                            @endif
                                                        @endforeach
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</div>
