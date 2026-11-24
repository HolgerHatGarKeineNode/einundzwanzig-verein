<?php

use App\Enums\ElectionRoundState;
use App\Livewire\Traits\WithViewer;
use App\Models\ElectionRound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The live turnout of an open round on the board's page, refreshed every few
 * seconds on its own so the page around it — modals, half-typed reasons —
 * stays as it is. A count, never a vote.
 *
 * When the round stops taking ballots (closed by another board member, or
 * its time ran out) it tells the page to refresh and stops polling.
 */
new class extends Component
{
    use WithViewer;

    #[Locked]
    public int $roundId;

    public function mount(int $roundId): void
    {
        $this->boardActor();

        $this->roundId = $roundId;
    }

    /**
     * @return array{cast: int, roll: int, open: bool}
     */
    #[Computed]
    public function turnout(): array
    {
        $this->boardActor();

        $round = ElectionRound::query()->findOrFail($this->roundId);

        return [
            'cast' => $round->votes()->count(),
            'roll' => $round->rollEntries()->count(),
            'open' => $round->stateAt(now()) === ElectionRoundState::Open,
        ];
    }

    public function rendered(): void
    {
        if (! $this->turnout['open']) {
            $this->dispatch('election-round-changed');
        }
    }

};
?>

@php
    $turnout = $this->turnout;
    $share = $turnout['roll'] > 0 ? round($turnout['cast'] / $turnout['roll'] * 100) : 0;
@endphp

<div @if ($turnout['open']) wire:poll.3s @endif class="relative" data-test="turnout-{{ $roundId }}">
    <p class="text-2xl font-bold leading-tight text-text-primary" aria-live="polite">
        <span class="tabular-nums" data-test="turnout-count">{{ __(':cast von :roll', ['cast' => $turnout['cast'], 'roll' => $turnout['roll']]) }}</span>
        <span class="text-lg font-normal text-text-secondary">{{ trans_choice('{1} hat abgestimmt|[0,*] haben abgestimmt', $turnout['cast']) }}</span>
    </p>
    <div class="mt-3 h-2 max-w-md bg-neutral-800" role="meter" aria-valuemin="0" aria-valuemax="{{ $turnout['roll'] }}" aria-valuenow="{{ $turnout['cast'] }}" aria-label="{{ __('Beteiligung') }}">
        <div class="h-full bg-text-primary" style="width: {{ $share }}%"></div>
    </div>
    <p class="mt-2 text-sm text-text-tertiary">{{ __(':share % Beteiligung. Aktualisiert sich alle drei Sekunden.', ['share' => $share]) }}</p>
</div>
