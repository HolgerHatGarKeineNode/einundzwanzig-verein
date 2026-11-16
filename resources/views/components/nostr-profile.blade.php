@props([
    'pubkey' => null,
    'name' => '',
    'picture' => null,
    'size' => 'sm',
    'trigger' => 'name',
])
{{--
    A person's Nostr name with avatar. A click opens a card with links and
    checks of the profile; the card is loaded from the server on first open,
    so a list of a hundred names renders only names and pictures.

    Without a valid pubkey (an erased member) it renders the name alone.
    trigger="avatar" makes only the avatar open the card and leaves the name
    plain text: on the ballot a click on the name selects the candidate.
--}}
@php
    $valid = \App\Support\NostrAvatars::isPubkey($pubkey);
    $picture = $valid ? (\App\Support\NostrAvatars::safeUrl($picture) ?? app(\App\Support\NostrAvatars::class)->pictureOf($pubkey)) : null;
    $avatarSize = $size === 'lg' ? 'size-9' : 'size-6';
    $initial = mb_strtoupper(mb_substr(trim((string) $name) !== '' ? trim((string) $name) : '?', 0, 1));
    // With the avatar as trigger, the caller's attributes go on the outer wrapper.
    $outer = $attributes;
    $panelId = 'nostr-card-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
    if ($trigger === 'avatar') {
        $attributes = new \Illuminate\View\ComponentAttributeBag;
    }
@endphp

@if (! $valid)
    <span {{ $attributes }}>{{ $name }}</span>
@else
    @if ($trigger === 'avatar')
        <span {{ $outer->class('inline-flex max-w-full items-center gap-2 align-middle') }}>
    @endif
    {{-- A native popover (top layer: no list or table clips it), wired by resources/js/nostrProfileChip.js.
         No child carries an Alpine expression; why is written there. --}}
    <span {{ $attributes->class(['inline-flex min-w-0 max-w-full align-middle', 'shrink-0' => $trigger === 'avatar']) }}
          x-data="nostrProfileChip(@js(route('nostr.profile-card', ['pubkey' => $pubkey], false)))">
        <button type="button" data-chip-trigger popovertarget="{{ $panelId }}"
                @class([
                    'group inline-flex min-h-11 min-w-0 max-w-full cursor-pointer items-center gap-2 rounded-md text-left [font:inherit] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                    '-my-2.5' => $trigger === 'name',
                    '-m-2.5 min-w-11 justify-center' => $trigger === 'avatar',
                ])
                aria-haspopup="dialog"
                aria-label="{{ __('Nostr-Profil von :name', ['name' => $name]) }}"
                data-test="nostr-profile" data-pubkey="{{ $pubkey }}">
            <span class="relative inline-flex {{ $avatarSize }} shrink-0 items-center justify-center overflow-hidden rounded-full bg-bg-elevated text-xs font-bold text-text-secondary ring-1 ring-border-default" aria-hidden="true">
                <span>{{ $initial }}</span>
                @if ($picture)
                    <img src="{{ $picture }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                         class="absolute inset-0 size-full object-cover" onerror="this.remove()">
                @endif
            </span>
            @if ($trigger === 'name')
                <span class="min-w-0 wrap-anywhere underline decoration-border-default decoration-dotted underline-offset-4 group-hover:decoration-current">{{ $name }}</span>
            @endif
        </button>

        {{-- Spans only: the chip often stands inside a <p>, and a <div> there makes the parser close the
             <p> and move the panel out of the chip. No display class on the panel: it would override
             the browser's rule that hides a closed popover. --}}
        <span id="{{ $panelId }}" popover="auto" data-chip-panel
             class="inset-auto m-0 max-h-[calc(100dvh-1rem)] w-[min(22rem,calc(100vw-1rem))] overflow-y-auto overscroll-contain rounded-lg border border-border-default bg-bg-surface p-0 text-left text-base font-normal text-text-primary shadow-lg"
             role="dialog" aria-label="{{ __('Nostr-Profil von :name', ['name' => $name]) }}">
            <span data-chip-loading class="block p-4 [&[hidden]]:hidden text-sm text-text-secondary" role="status">{{ __('Profil wird geladen …') }}</span>
            <span data-chip-failed hidden class="flex items-center justify-between gap-3 p-4 text-sm text-text-secondary [&[hidden]]:hidden" role="status">
                <span>{{ __('Das Profil ließ sich nicht laden.') }}</span>
                <button type="button" data-chip-retry class="inline-flex min-h-11 items-center rounded-md border border-border-default px-3 text-sm text-text-primary hover:bg-bg-elevated">{{ __('Erneut') }}</button>
            </span>
            <span data-chip-body class="block"></span>
        </span>
    </span>
    @if ($trigger === 'avatar')
            <span class="min-w-0 wrap-anywhere">{{ $name }}</span>
        </span>
    @endif
@endif
