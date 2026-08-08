<?php

use App\Support\NostrAuth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public bool $isLoggedIn = false;

    #[Locked]
    public string $location = 'sidebar'; // 'sidebar' or 'navbar'

    #[Locked]
    public ?string $nostrChallenge = null;

    public function mount(): void
    {
        $this->isLoggedIn = NostrAuth::check();

        if (! $this->isLoggedIn) {
            // Sidebar + navbar mount the same component on the same page; using
            // currentOrIssueChallenge keeps both rendered data-attributes
            // pointing at the same live session value.
            $this->nostrChallenge = NostrAuth::currentOrIssueChallenge();
        }
    }

    /**
     * JS-driven fallback: re-issue the challenge if the client cannot find
     * one in the rendered snapshot (e.g. after a long-lived tab where the
     * Volt component snapshot drifted out of sync with the session).
     */
    public function requestNostrChallenge(): string
    {
        $challenge = NostrAuth::issueChallenge();
        $this->nostrChallenge = $challenge;

        return $challenge;
    }

    #[On('nostrLoggedIn')]
    public function handleNostrLoggedIn($signedEvent = null): void
    {
        NostrAuth::loginWithSignedEvent($signedEvent);

        $this->js('window.location.reload(true);');
    }

    #[On('nostrLoggedOut')]
    public function handleNostrLoggedOut(): void
    {
        $this->isLoggedIn = false;
    }
}
?>

<div x-data="nostrLogin" data-nostr-challenge="{{ $nostrChallenge ?? '' }}">
    @if($isLoggedIn)
        {{-- Vor dem Absenden den Klartext-Cache des Chats vom Geraet raeumen.
             Der Handler laedt dafuer NICHTS nach; ohne Chat auf der Seite ist es
             ein Aufruf der IndexedDB-API. Siehe resources/js/nostrLogout.js. --}}
        <form method="post" action="{{ route('logout') }}"
              x-data="nostrLogout"
              x-on:submit.prevent="submitAfterWipe($event.target)">
            @csrf
            <flux:button variant="ghost" icon="arrow-right-start-on-rectangle" type="submit" wire:click="$dispatch('nostrLoggedOut')">Logout</flux:button>
        </form>
    @else
        <flux:button variant="primary"
                     icon="user"
                     @click="openNostrLogin"
                     x-bind:disabled="nostrLoginInProgress"
                     x-bind:aria-busy="nostrLoginInProgress"
                     class="cursor-pointer">
            <span x-show="!nostrLoginInProgress">Mit Nostr verbinden</span>
            <span x-show="nostrLoginInProgress" x-cloak class="inline-flex items-center gap-2">
                <flux:icon.arrow-path class="animate-spin size-4" aria-hidden="true"/>
                Signiere…
            </span>
        </flux:button>
    @endif

    {{-- Full-viewport progress overlay. Visible while the wallet-signing
         round-trip is running. Locks input by capturing pointer events and
         intercepting Escape/Tab so the user cannot interact with anything
         underneath until the redirect resolves (or the flow errors out). --}}
    <div x-show="nostrLoginInProgress"
         x-cloak
         x-transition.opacity.duration.150ms
         role="dialog"
         aria-modal="true"
         x-bind:aria-busy="nostrLoginInProgress"
         aria-labelledby="nostr-login-progress-heading-{{ $location }}"
         aria-describedby="nostr-login-progress-description-{{ $location }}"
         @keydown.window.escape="if (nostrLoginInProgress) { $event.preventDefault(); $event.stopPropagation(); }"
         @keydown.window.tab="if (nostrLoginInProgress) { $event.preventDefault(); $event.stopPropagation(); }"
         x-effect="document.body.style.overflow = nostrLoginInProgress ? 'hidden' : ''"
         class="fixed inset-0 z-[100] flex items-center justify-center bg-zinc-950/70 backdrop-blur-md">
        <div class="mx-4 w-full max-w-md rounded-2xl bg-white px-8 py-10 text-center shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
            <div class="relative mx-auto flex size-20 items-center justify-center">
                <span class="absolute inset-0 animate-ping rounded-full bg-amber-500/20" aria-hidden="true"></span>
                <span class="absolute inset-2 rounded-full bg-amber-500/10" aria-hidden="true"></span>
                <flux:icon.arrow-path class="relative size-10 animate-spin text-amber-600 dark:text-amber-400"
                                      aria-hidden="true"/>
            </div>

            <flux:heading id="nostr-login-progress-heading-{{ $location }}" size="lg" class="mt-6">
                Signiere mit deinem Nostr-Wallet
            </flux:heading>

            {{-- Der Text nennt beide Wege und behauptet keinen davon.

                 Vorher stand hier „in deiner Browser-Extension" — auf dem Telefon
                 gibt es die in aller Regel nicht. Dort liefert window.nostr.js
                 (public/dist/window.nostr.min.js.js, konfiguriert ueber
                 window.wnjParams im Layout) das window.nostr-Objekt nach und
                 oeffnet beim Signieren einen eigenen Dialog. Der liegt mit einem
                 sehr hohen z-index UEBER diesem Overlay, ist also bedienbar —
                 aber der alte Satz schickte den Nutzer zu einer Erweiterung, die
                 er nicht hat, waehrend die Loesung vor ihm stand. --}}
            <flux:text id="nostr-login-progress-description-{{ $location }}" class="mt-3 text-zinc-600 dark:text-zinc-400">
                Bestätige die Anmeldung in deiner Browser-Erweiterung oder im
                Nostr-Dialog über dieser Seite. Danach geht es automatisch weiter.
            </flux:text>

            <flux:text size="sm" class="mt-6 text-zinc-500 dark:text-zinc-500">
                Schließe dieses Fenster nicht.
            </flux:text>
        </div>
    </div>
</div>
