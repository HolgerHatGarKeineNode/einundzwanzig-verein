<?php

use App\Enums\ProjectProposalStatus;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A project proposal: what is asked for, its status, and the contact of the
 * submitter for the board and the submitter.
 *
 * The parts that act are child components, each asking its own policy:
 * `show-vote-panel` (own vote, board panel, payout, tallies),
 * `show-chat-room` (the private room of board and submitter) and
 * `show-description-images` (the board's image takedown). A child that
 * changed the proposal tells the page `project-proposal-changed`.
 */
new class extends Component
{
    #[Locked]
    public ProjectProposal $projectProposal;

    /** The session the page was rendered for, handed to the chat island. */
    #[Locked]
    public ?string $currentPubkey = null;

    public function mount(ProjectProposal $projectProposal): void
    {
        $this->projectProposal = $projectProposal;
        $this->currentPubkey = NostrAuth::check() ? NostrAuth::pubkey() : null;
    }

    /**
     * A vote, a payout or an image removal changed the proposal: read it
     * again, with its status and description.
     */
    #[On('project-proposal-changed')]
    public function proposalChanged(): void
    {
        $this->projectProposal->refresh();
        unset($this->status);
    }

    /**
     * Der abgeleitete Status des Antrags — dieselbe Regel wie in der Übersicht.
     * Only the board votes count, so only they are read.
     */
    #[Computed]
    public function status(): ProjectProposalStatus
    {
        $boardVotes = Vote::query()
            ->where('project_proposal_id', $this->projectProposal->id)
            ->whereIn('einundzwanzig_pleb_id', ProjectProposal::boardPlebIds())
            ->pluck('value');

        $this->projectProposal->setAttribute('board_approvals_count', $boardVotes->filter(fn (mixed $value): bool => (bool) $value)->count());
        $this->projectProposal->setAttribute('board_rejections_count', $boardVotes->reject(fn (mixed $value): bool => (bool) $value)->count());

        return $this->projectProposal->status();
    }

    /**
     * Darf der Betrachter die Kontaktangabe des Einreichers sehen?
     * Kontaktdaten sind personenbezogen und stehen auf einer öffentlich
     * erreichbaren Seite — deshalb nur Vorstand und Einreicher.
     */
    #[Computed]
    public function canSeeContact(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('viewContact', $this->projectProposal);
    }

    /**
     * Darf der Betrachter den privaten Chatraum anlegen? Nur Vorstand, und nur
     * solange es keinen gibt.
     */
    #[Computed]
    public function canCreateChatRoom(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('createChatRoom', $this->projectProposal);
    }

    /**
     * Darf der Betrachter den Chatraum sehen und nutzen? Vorstand und Einreicher.
     */
    #[Computed]
    public function canSeeChatRoom(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('viewChatRoom', $this->projectProposal);
    }

    /**
     * Darf der Betrachter den Chat-Abschnitt überhaupt sehen?
     *
     * Weiter gefasst als die beiden Rechte darunter, und zwar mit Absicht: Ein
     * Einreicher, dessen Raum noch nicht angelegt ist, hat weder `viewChatRoom`
     * (das verlangt einen existierenden Raum) noch `createChatRoom` (nur
     * Vorstand). Er fiel damit durch beide Gates und sah vom Chat gar nichts —
     * auch nicht den Hinweis, der für genau diesen Fall im Markup steht.
     *
     * `viewContact` ist der richtige Zuschnitt: Der Antragsraum ist der private
     * Kanal zwischen Vorstand und Einreicher, also derselbe Personenkreis, dem
     * auch die Kontaktangaben gehören.
     */
    #[Computed]
    public function canSeeChatSection(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('viewContact', $this->projectProposal);
    }

    #[Computed]
    public function canRemoveDescriptionImages(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('removeDescriptionImage', $this->projectProposal);
    }
};
?>

<div class="mx-auto w-full max-w-5xl pb-32">
    <div>
        <div class="mb-6">
            <flux:button :href="route('association.projectSupport')" variant="ghost" icon="chevron-left" class="min-h-11 -ml-3">
                Zurück zur Übersicht
            </flux:button>
        </div>

        {{-- Auf dem Telefon steht der ANTRAG vor der Abstimmung.

             Vorher drehten `order-1`/`order-2` die Spalten um: Wer die Seite mobil
             oeffnete, sah „Deine Stimme", „Vorstandsentscheidung" und
             „Stimmungsbild" — aber nirgends, worueber abgestimmt wird. Eine
             Abstimmung ohne ihren Gegenstand ist keine Verkuerzung, sondern eine
             Aufforderung, uninformiert zu klicken.

             Jetzt folgt die Stapelung der DOM-Reihenfolge (Hauptspalte, dann
             Seitenspalte) — die order-Klassen sind ersatzlos weg. Damit stimmen
             Lese-, Tab- und DOM-Reihenfolge auf dem Telefon wieder ueberein.
             Der Zweispalter ab `lg` bleibt unveraendert. --}}
        <div class="flex flex-col lg:flex-row lg:gap-8">
            {{-- Hauptspalte --}}
            <div class="flex-1 min-w-0">
                <x-page-header :title="$projectProposal->name" class="mb-6">
                    {{-- State in word and icon; the colour comes from the enum (P3/D9). --}}
                    <div class="mt-4">
                        <flux:badge :color="$this->status->color()" :icon="$this->status->icon()">{{ $this->status->label() }}</flux:badge>
                    </div>
                </x-page-header>

                {{-- Eckdaten --}}
                <div class="mb-6 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-sm text-text-tertiary">Fördersumme</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-text-primary">
                                {{ number_format($projectProposal->support_in_sats, 0, ',', '.') }} Sats
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-text-tertiary">Eingereicht am</dt>
                            <dd class="mt-1 text-base text-text-secondary">
                                {{ $projectProposal->created_at->translatedFormat('d.m.Y') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-text-tertiary">Eingereicht von</dt>
                            <dd class="mt-1 flex items-center gap-2 text-base">
                                <img class="size-6 rounded-full shrink-0"
                                     src="{{ $projectProposal->einundzwanzigPleb->profile?->picture }}"
                                     onerror="this.src='{{ asset('einundzwanzig-alpha.jpg') }}'"
                                     alt="" width="24" height="24" loading="lazy">
                                <a href="https://njump.me/{{ $projectProposal->einundzwanzigPleb->npub }}"
                                   target="_blank" rel="noopener"
                                   class="flex min-h-11 min-w-0 items-center break-all text-text-secondary hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                    <x-pleb-name :pleb="$projectProposal->einundzwanzigPleb" />
                                </a>
                            </dd>
                        </div>
                        @if($projectProposal->website)
                            <div>
                                <dt class="text-sm text-text-tertiary">Webseite</dt>
                                <dd class="mt-1 text-base">
                                    <a href="{{ $projectProposal->website }}" target="_blank" rel="noopener"
                                       class="inline-flex min-h-11 items-center break-all text-text-secondary hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                        {{ str($projectProposal->website)->replaceFirst('https://', '')->limit(40) }}
                                    </a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                {{-- Auf dem Telefon kleiner: Das Bild sitzt jetzt zwischen Eckdaten
                     und Beschreibung, also auf dem Weg zur Abstimmung. Mit
                     `max-w-md` waere es dort 390px hoch (Quadrat auf voller
                     Viewport-Breite) und schoebe die Kurzfassung um einen halben
                     Bildschirm nach unten. Ab `sm` unveraendert. --}}
                <figure class="mb-6">
                    <img class="w-full max-w-56 sm:max-w-md aspect-square rounded-lg bg-bg-elevated object-cover"
                         src="{{ $projectProposal->getSignedMediaUrl('main', 60, 'preview') }}"
                         alt="" width="448" height="448" loading="lazy" decoding="async">
                </figure>

                <div class="prose max-w-prose break-words [&_code]:break-all [&_a]:break-all">
                    {{-- safeDescription(), NIE description: die Spalte trägt vom
                         Antragsteller geschriebenes HTML, und {!! !!} escapt nicht.
                         Begründung am Accessor. --}}
                    {!! $projectProposal->safeDescription() !!}
                </div>


                @if($this->canRemoveDescriptionImages)
                    <livewire:association.project-support.show-description-images :project-proposal="$projectProposal" :key="'description-images-'.$projectProposal->id" />
                @endif
            </div>

            {{-- Seitenspalte: Vorstand, Abstimmung, Kontakt

                 `flex flex-col gap-4` statt `space-y-4`: gleiche Abstaende, aber
                 die Karten lassen sich per `order` stapeln. Auf `lg` laufen die
                 order-Werte 1..5 in DOM-Reihenfolge — die Spalte sieht dort exakt
                 aus wie vorher. Auf dem Telefon tauscht nur das Vorstands-Panel
                 die Stelle mit den beiden Zaehlkarten (Begruendung dort).

                 `mt-6 lg:mt-0` statt `mb-6 lg:mb-0`: Die Spalte steht mobil jetzt
                 UNTER der Hauptspalte; der Abstand gehoert entsprechend nach oben,
                 sonst haengt am Seitenende Leerraum.

                 KEIN `lg:sticky lg:top-6` mehr (war bis zum Chat-Umzug hier):
                 Mit dem Chat als erster Karte ist die Spalte fuer jede Rolle, die
                 ihn ueberhaupt sieht, hoeher als ein Laptop-Viewport — Chat ~480px
                 + Deine Stimme + bis zu drei weitere Karten. Eine sticky-Box, die
                 hoeher ist als der Viewport, klebt mit ihrem KOPF oben fest; ihr
                 unteres Ende ist dann durch Scrollen nicht mehr erreichbar
                 (Kontakt/Stimmungsbild waeren dauerhaft abgeschnitten). Die
                 Alternative — `max-h` plus eigener Scrollcontainer — scheidet aus:
                 Sie clippt die absolut positionierten Overlays der Chat-Insel
                 (Emoji-Panel, Mention-Liste) und legt eine zweite Scrollflaeche um
                 eine, die selbst schon scrollt. Also scrollt die Spalte normal mit.

                 `lg:self-start` bleibt: verhindert, dass die Spalte in der
                 Flex-Zeile auf die Hoehe der Hauptspalte gestreckt wird. --}}
            <div class="lg:w-80 xl:w-96 shrink-0 flex flex-col gap-4 mt-6 lg:mt-0 lg:self-start">

                {{-- Chat, own vote, board panel and tallies are child components
                     with a `display: contents` root, so their cards are items of
                     this column and keep their `order` (chat first, contact last). --}}
                @if($this->canSeeChatSection)
                    <livewire:association.project-support.show-chat-room :project-proposal="$projectProposal" :current-pubkey="$currentPubkey" :key="'chat-room-'.$projectProposal->id" />
                @endif

                <livewire:association.project-support.show-vote-panel :project-proposal="$projectProposal" :key="'vote-panel-'.$projectProposal->id" />

                {{-- Kontakt: nur Vorstand und Einreicher. Letzte Karte auf jedem
                     Viewport — Nachschlagewerk, keine Handlung. --}}
                @if($this->canSeeContact)
                    <div class="order-5 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                        <h2 class="text-base font-semibold text-text-primary mb-3">
                            Kontakt zum Einreicher
                        </h2>
                        @if($projectProposal->contact_via_nostr_dm)
                            <div class="flex items-start gap-2">
                                <flux:icon name="envelope" variant="micro" class="mt-1 shrink-0 text-text-tertiary" aria-hidden="true"/>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-text-secondary">Nostr-DM erwünscht</p>
                                    <x-copy-field class="mt-2" :value="$projectProposal->einundzwanzigPleb->npub" label="npub" />
                                </div>
                            </div>
                        @elseif($projectProposal->contact_alternative)
                            <div class="flex items-start gap-2">
                                <flux:icon name="envelope" variant="micro" class="mt-1 shrink-0 text-text-tertiary" aria-hidden="true"/>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-text-secondary">Bevorzugter Kanal</p>
                                    <x-copy-field class="mt-2" :value="$projectProposal->contact_alternative" label="Kontaktangabe" />
                                </div>
                            </div>
                        @else
                            <div class="flex items-start gap-2">
                                <flux:icon name="exclamation-triangle" variant="micro" class="mt-1 shrink-0 text-warning" aria-hidden="true"/>
                                <div>
                                    <p class="text-sm text-text-primary">Kein Kontaktweg hinterlegt.</p>
                                    <p class="mt-1 text-sm text-text-secondary">
                                        Der Einreicher hat Direktnachrichten abgewählt und keinen anderen Kanal genannt.
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

            </div>
        </div>
    </div>

    {{-- Die Chat-Insel NUR hier laden, nicht in app.js: Der Import von
         @einundzwanzig/group hat Seiteneffekte (welshman-Singletons, eine
         AUTH-Policy fuer Relays, localStorage und IndexedDB) und zieht einen
         eigenen ~950-KB-Chunk. Beides gehoert nicht auf jede Vereinsseite. --}}
    {{-- Die Alpine-Komponente ist in app.js registriert; das Chat-SDK laedt sie
         selbst per dynamischem Import beim Klick. Hier bleibt nur die
         Space-Adresse: Das Package liest window.__nostrSpace beim Laden und
         faellt sonst auf ws://localhost:3334 zurueck — im Betrieb also auf gar
         nichts. Es setzt das sonst in seinem eigenen head-Partial, das wir
         bewusst nicht einbinden. --}}
    @if($this->canSeeChatRoom || $this->canCreateChatRoom)
        @push('head')
            <script>window.__nostrSpace = @js(config('group.space_url'))</script>
        @endpush
    @endif
</div>
