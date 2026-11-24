{{-- The run sheet of one election on the board's page: its five stations and the one next step (App\Services\Election\RunSheet). The buttons call the Livewire component that renders it. --}}
@props(['item', 'step', 'selected' => false, 'creating' => false])

{{-- The run sheet: five stations of a real sequence, so they are an ordered list. --}}
<ol class="mt-6 grid gap-x-2 gap-y-1 md:grid-cols-5" aria-label="{{ __('Ablaufplan von „:name“', ['name' => $item->name]) }}" data-test="phase-rail">
    @foreach (app(\App\Services\Election\RunSheet::class)->stationsOf($item) as $station)
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
    @php $primary = $selected && ! $creating; @endphp
    <div class="flex shrink-0 flex-col gap-2 sm:flex-row-reverse sm:items-center">
    @if ($step['href'] !== null)
        <flux:button :href="$step['href']" target="_blank" :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-action">{{ $step['label'] }}</flux:button>
    @elseif ($step['click'] !== null)
        <flux:button wire:click="{{ $selected ? $step['click'] : 'selectElection('.$item->id.')' }}"
                     :variant="$primary ? 'primary' : 'outline'" class="min-h-11 w-full shrink-0 sm:w-auto sm:max-w-sm" data-test="next-step-action">
            {{ $selected ? $step['label'] : __('Wahl öffnen') }}
        </flux:button>
    @elseif ($step['action'] !== null)
        @php
            $stepClick = $selected ? "showSection('".$step['action']."')" : 'selectElection('.$item->id.')';
        @endphp
        @if ($step['action'] === 'announce')
            @if ($selected)
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
    @if ($selected && $step['secondary_click'] !== null)
        <flux:button wire:click="{{ $step['secondary_click'] }}" variant="ghost" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="next-step-secondary">{{ $step['secondary_label'] }}</flux:button>
    @endif
    </div>
</div>
