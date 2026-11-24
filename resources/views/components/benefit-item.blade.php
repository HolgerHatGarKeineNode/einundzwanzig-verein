@props([
    'icon',
    'title',
    'description' => null,
    'active' => false,
    'compact' => false,
])

{{--
    One member service (P3/D3), the same neutral row on the benefits page and
    in the summary on the profile: a grey Heroicon, the name, the state and
    one line of description. No colour per service; state is a word plus the
    success/neutral badge, never the tint alone.

    Slots: `badges` beside the name (e.g. "Neu", "Experimentell"); the default
    slot below the description (address, buttons, guide). Compact drops the
    state badge and the default slot.
--}}
<li {{ $attributes->class(['flex items-start gap-4', 'px-4 py-6 sm:px-6' => ! $compact]) }}>
    <flux:icon :name="$icon" class="mt-0.5 size-6 shrink-0 text-text-tertiary" aria-hidden="true" />
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            @if ($compact)
                <h3 class="text-base font-semibold text-text-primary">{{ $title }}</h3>
            @else
                <h2 class="text-lg font-bold leading-snug text-text-primary">{{ $title }}</h2>
            @endif
            {{ $badges ?? '' }}
            @unless ($compact)
                @if ($active)
                    <flux:badge color="green" size="sm" icon="check">Aktiv</flux:badge>
                @else
                    <flux:badge size="sm" icon="lock-closed">Nur Mitglieder</flux:badge>
                @endif
            @endunless
        </div>
        @if (filled($description))
            <p class="mt-1 max-w-prose text-base leading-normal text-text-secondary">{{ $description }}</p>
        @endif
        @unless ($compact)
            @if ($slot->isNotEmpty())
                <div class="mt-4 flex flex-col gap-4">{{ $slot }}</div>
            @endif
        @endunless
    </div>
</li>
