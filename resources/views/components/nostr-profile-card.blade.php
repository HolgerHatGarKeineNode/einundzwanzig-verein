{{--
    The card a Nostr name chip opens (x-nostr-profile). Rendered by
    App\Http\Controllers\Nostr\ProfileCard and inserted with x-html.
--}}
@php
    $checks = [
        ['label' => __('Profil (kind 0) gefunden'), 'ok' => $hasMetadata, 'detail' => $hasMetadata ? ($updatedAt ? __('zuletzt geändert :date', ['date' => $updatedAt->timezone('Europe/Berlin')->format('d.m.Y')]) : null) : __('Weder Relays noch Portal kennen ein Profil.'), 'test' => 'metadata'],
        ['label' => __('Name gesetzt'), 'ok' => $name !== null, 'detail' => null, 'test' => 'name'],
        ['label' => __('Profilbild gesetzt'), 'ok' => $picture !== null, 'detail' => null, 'test' => 'picture'],
        ['label' => __('Lightning-Adresse'), 'ok' => $lightning !== null, 'detail' => $lightning, 'test' => 'lightning'],
    ];
    $links = [
        ['label' => 'njump.me', 'href' => "https://njump.me/{$npub}"],
        ['label' => 'Primal', 'href' => "https://primal.net/p/{$npub}"],
        ['label' => 'Snort', 'href' => "https://snort.social/p/{$npub}"],
        ['label' => 'Iris', 'href' => "https://iris.to/{$npub}"],
    ];
@endphp

<div class="flex flex-col" data-test="nostr-profile-card">
    <div class="flex items-start gap-3 p-4">
        <span class="relative inline-flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-bg-elevated text-base font-bold text-text-secondary ring-1 ring-border-default" aria-hidden="true" x-data="{ broken: false }">
            <span>{{ mb_strtoupper(mb_substr($name ?? '?', 0, 1)) }}</span>
            @if ($picture)
                <img src="{{ $picture }}" alt="" referrerpolicy="no-referrer" class="absolute inset-0 size-full object-cover" x-show="! broken" x-on:error="broken = true">
            @endif
        </span>
        <div class="min-w-0 flex-1">
            <p class="break-words font-bold text-text-primary">{{ $name ?? __('Ohne Namen') }}</p>
            @if ($displayName && $displayName !== $name)
                <p class="break-words text-sm text-text-secondary">{{ $displayName }}</p>
            @endif
            @if ($nip05)
                <p class="break-all text-sm text-text-secondary">{{ $nip05['handle'] }}</p>
            @endif
        </div>
    </div>

    @if ($about)
        <p class="break-words px-4 pb-3 text-sm text-text-secondary">{{ $about }}</p>
    @endif

    <div class="flex items-center gap-2 border-t border-border-subtle px-4 py-2" x-data="{ copied: false }">
        <code class="min-w-0 flex-1 truncate font-mono text-xs text-text-secondary" title="{{ $npub }}" data-test="card-npub">{{ $npub }}</code>
        <button type="button" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-md px-2 text-sm text-text-secondary hover:text-text-primary"
                x-on:click="navigator.clipboard?.writeText(@js($npub)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
            <flux:icon.clipboard-document variant="micro" aria-hidden="true" />
            <span x-text="copied ? @js(__('kopiert')) : @js(__('npub kopieren'))">{{ __('npub kopieren') }}</span>
        </button>
    </div>

    <ul class="border-t border-border-subtle px-4 py-2 text-sm" aria-label="{{ __('Prüfungen') }}">
        @foreach ($checks as $check)
            <li class="flex items-start gap-2 py-1" data-test="check-{{ $check['test'] }}" data-ok="{{ $check['ok'] ? 'yes' : 'no' }}">
                @if ($check['ok'])
                    <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0 text-success" aria-hidden="true" />
                @else
                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
                @endif
                <span class="min-w-0 break-words text-text-primary">{{ $check['label'] }}<span class="sr-only">: {{ $check['ok'] ? __('ja') : __('nein') }}</span>@if ($check['detail'])<span class="text-text-tertiary"> · {{ $check['detail'] }}</span>@endif</span>
            </li>
        @endforeach

        @if ($nip05)
            <li class="flex items-start gap-2 py-1" data-test="check-nip05"
                x-data="{
                    state: 'checking',
                    init() {
                        fetch(@js('https://'.$nip05['domain'].'/.well-known/nostr.json?name='.rawurlencode($nip05['name'])), { credentials: 'omit', referrerPolicy: 'no-referrer' })
                            .then((response) => response.ok ? response.json() : Promise.reject())
                            .then((json) => { this.state = json?.names?.[@js($nip05['name'])] === @js($pubkey) ? 'ok' : 'bad' })
                            .catch(() => { this.state = 'unknown' })
                    },
                }" x-bind:data-state="state">
                <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0 text-success" aria-hidden="true" x-show="state === 'ok'" x-cloak />
                <flux:icon.x-circle variant="micro" class="mt-0.5 shrink-0 text-danger" aria-hidden="true" x-show="state === 'bad'" x-cloak />
                <flux:icon.question-mark-circle variant="micro" class="mt-0.5 shrink-0 text-text-tertiary" aria-hidden="true" x-show="state === 'checking' || state === 'unknown'" />
                <span class="min-w-0 break-words text-text-primary">{{ __('NIP-05') }}
                    <span class="text-text-tertiary" x-show="state === 'checking'">· {{ __('wird geprüft …') }}</span>
                    <span class="text-text-tertiary" x-show="state === 'ok'" x-cloak>· {{ __('bestätigt von :domain', ['domain' => $nip05['domain']]) }}</span>
                    <span class="text-danger" x-show="state === 'bad'" x-cloak>· {{ __(':domain nennt einen anderen Schlüssel', ['domain' => $nip05['domain']]) }}</span>
                    <span class="text-text-tertiary" x-show="state === 'unknown'" x-cloak>· {{ __(':domain antwortet nicht', ['domain' => $nip05['domain']]) }}</span>
                </span>
            </li>
        @else
            <li class="flex items-start gap-2 py-1" data-test="check-nip05" data-state="none">
                <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
                <span class="min-w-0 text-text-primary">{{ __('NIP-05') }}<span class="text-text-tertiary"> · {{ __('nicht angegeben') }}</span></span>
            </li>
        @endif
    </ul>

    @if ($isBoard)
        <ul class="border-t border-border-subtle px-4 py-2 text-sm" aria-label="{{ __('Im Portal') }}" data-test="card-portal">
            @if ($member === null)
                <li class="flex items-start gap-2 py-1">
                    <flux:icon.information-circle variant="micro" class="mt-0.5 shrink-0 text-text-tertiary" aria-hidden="true" />
                    <span class="text-text-primary">{{ __('Kein Konto im Portal') }}</span>
                </li>
            @else
                <li class="flex items-start gap-2 py-1">
                    <flux:icon.identification variant="micro" class="mt-0.5 shrink-0 text-text-tertiary" aria-hidden="true" />
                    <span class="text-text-primary">{{ __('Mitgliedschaft: :status', ['status' => $member->association_status->label()]) }}</span>
                </li>
                <li class="flex items-start gap-2 py-1" data-test="check-fee" data-ok="{{ $memberPaid ? 'yes' : 'no' }}">
                    @if ($memberPaid)
                        <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0 text-success" aria-hidden="true" />
                    @else
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
                    @endif
                    <span class="text-text-primary">{{ $memberPaid ? __('Beitrag :year bezahlt', ['year' => now()->year]) : __('Beitrag :year offen', ['year' => now()->year]) }}</span>
                </li>
                <li class="flex items-start gap-2 py-1" data-test="check-inbox" data-ok="{{ $memberInbox ? 'yes' : 'no' }}">
                    @if ($memberInbox)
                        <flux:icon.check-circle variant="micro" class="mt-0.5 shrink-0 text-success" aria-hidden="true" />
                    @else
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
                    @endif
                    <span class="min-w-0 break-words text-text-primary">{{ $memberInbox ? __('Nostr-Postfach (kind 10050) eingerichtet') : __('Kein Nostr-Postfach: Vereins-DMs kommen nicht an') }}</span>
                </li>
            @endif
        </ul>
    @endif

    <div class="flex flex-wrap gap-x-1 border-t border-border-subtle px-2 py-1" data-test="card-links">
        @foreach ($links as $link)
            <a href="{{ $link['href'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-1 rounded-md px-2 text-sm text-text-secondary hover:text-text-primary">
                {{ $link['label'] }}<flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true" />
            </a>
        @endforeach
        <a href="nostr:{{ $npub }}" class="inline-flex min-h-11 items-center gap-1 rounded-md px-2 text-sm text-text-secondary hover:text-text-primary">{{ __('In Nostr-App öffnen') }}</a>
        @if ($website)
            <a href="{{ $website }}" target="_blank" rel="noopener noreferrer nofollow" class="inline-flex min-h-11 items-center gap-1 rounded-md px-2 text-sm text-text-secondary hover:text-text-primary">{{ __('Webseite') }}<flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true" /></a>
        @endif
    </div>
</div>
