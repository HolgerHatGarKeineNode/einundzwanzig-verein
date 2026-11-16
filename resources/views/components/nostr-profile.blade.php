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
    // With the avatar as trigger, the caller's attributes go on the wrapper, not on the dropdown.
    $outer = $attributes;
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
    <span class="inline-flex min-w-0 max-w-full align-middle"
          x-data="{
            html: null,
            failed: false,
            load() {
                if (this.html !== null) return;
                this.failed = false;
                fetch(@js(route('nostr.profile-card', ['pubkey' => $pubkey], false)), { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then((response) => response.ok ? response.text() : Promise.reject(response.status))
                    .then((text) => { this.html = text })
                    .catch(() => { this.failed = true })
            },
        }">
    <flux:dropdown position="bottom" align="start" {{ $attributes->class(['inline-flex! max-w-full align-middle', 'shrink-0' => $trigger === 'avatar']) }}>
        <button type="button" x-on:click="load()"
                @class([
                    'group inline-flex min-h-11 min-w-0 max-w-full cursor-pointer items-center gap-2 rounded-md text-left [font:inherit] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                    '-my-2.5' => $trigger === 'name',
                    '-m-2.5 min-w-11 justify-center' => $trigger === 'avatar',
                ])
                aria-haspopup="dialog"
                aria-label="{{ __('Nostr-Profil von :name', ['name' => $name]) }}"
                data-test="nostr-profile" data-pubkey="{{ $pubkey }}">
            <span class="relative inline-flex {{ $avatarSize }} shrink-0 items-center justify-center overflow-hidden rounded-full bg-bg-elevated text-xs font-bold text-text-secondary ring-1 ring-border-default" aria-hidden="true" x-data="{ broken: false }">
                <span>{{ $initial }}</span>
                @if ($picture)
                    <img src="{{ $picture }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                         class="absolute inset-0 size-full object-cover" x-show="! broken" x-on:error="broken = true">
                @endif
            </span>
            @if ($trigger === 'name')
                <span class="min-w-0 wrap-anywhere underline decoration-border-default decoration-dotted underline-offset-4 group-hover:decoration-current">{{ $name }}</span>
            @endif
        </button>

        <flux:popover class="max-h-[calc(100dvh-2rem)] w-[min(22rem,calc(100vw-2rem))] overflow-y-auto overscroll-contain p-0! text-base font-normal"
                      x-on:click="$event.target.closest('a, button, input') || $event.preventDefault()" role="dialog" aria-label="{{ __('Nostr-Profil von :name', ['name' => $name]) }}">
            <div x-show="html === null && ! failed" class="p-4 text-sm text-text-secondary" role="status">{{ __('Profil wird geladen …') }}</div>
            <div x-show="failed" x-cloak class="flex items-center justify-between gap-3 p-4 text-sm text-text-secondary" role="status">
                <span>{{ __('Das Profil ließ sich nicht laden.') }}</span>
                <flux:button size="sm" x-on:click="load()" class="min-h-11">{{ __('Erneut') }}</flux:button>
            </div>
            <div x-html="html"></div>
        </flux:popover>
    </flux:dropdown>
    </span>
    @if ($trigger === 'avatar')
            <span class="min-w-0 wrap-anywhere">{{ $name }}</span>
        </span>
    @endif
@endif
