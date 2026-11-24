<?php

use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The private chat room of a project proposal: the room the board and the
 * submitter share, its creation by the board and the repair of a dead room
 * reference.
 *
 * A child of `association.project-support.show`, mounted for the people the
 * section belongs to (`viewContact`: board and submitter). Its root is
 * `display: contents`, so the card stays the first item of the page's side
 * column. The island in `projectChatRoom.js` reports a created room to this
 * component through `$wire.call('storeChatRoom')`.
 *
 * Callable on its own like every Livewire component: every request asks
 * `viewContact` again, and each write asks its own policy.
 */
new class extends Component
{
    #[Locked]
    public ProjectProposal $projectProposal;

    #[Locked]
    public ?string $currentPubkey = null;

    public function mount(ProjectProposal $projectProposal, ?string $currentPubkey = null): void
    {
        $this->projectProposal = $projectProposal;
        $this->currentPubkey = $currentPubkey;
        $this->authorizeSection();
    }

    public function hydrate(): void
    {
        $this->authorizeSection();
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
     * Darf der Betrachter den eingetragenen Raumverweis zurücksetzen? Nur
     * Vorstand, und nur wenn überhaupt einer eingetragen ist.
     */
    #[Computed]
    public function canResetChatRoom(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('resetChatRoom', $this->projectProposal);
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
     * Die Pubkeys, die in den Raum gehören — für die Insel, die daraus die
     * kind-9000-Events baut.
     *
     * @return list<string>
     */
    #[Computed]
    public function chatRoomMemberPubkeys(): array
    {
        return $this->projectProposal->nostrGroupMemberPubkeys();
    }

    /**
     * Vermerkt den angelegten Chatraum am Antrag.
     *
     * Wird von der Insel gerufen, nachdem die NIP-29-Events durch sind. Die
     * Raum-ID kommt NICHT aus dem Aufruf, sondern wird hier neu berechnet: Eine
     * öffentliche Livewire-Methode ist direkt aufrufbar, und ein gefälschter
     * Wert würde den Antrag dauerhaft auf einen fremden Raum zeigen lassen.
     * Der übergebene Wert dient nur als Abgleich.
     */
    public function storeChatRoom(string $roomId): void
    {
        Gate::forUser(NostrAuth::user())->authorize('createChatRoom', $this->projectProposal);

        $expected = $this->projectProposal->nostrGroupId();

        if ($roomId !== $expected) {
            Flux::toast(
                text: 'Der gemeldete Raum passt nicht zu diesem Antrag. Es wurde nichts gespeichert.',
                variant: 'danger',
            );

            return;
        }

        // Direkte Zuweisung: nostr_group_h ist bewusst nicht in $fillable.
        $this->projectProposal->nostr_group_h = $expected;
        $this->projectProposal->nostr_group_created_at = now();
        $this->projectProposal->save();

        unset($this->canCreateChatRoom, $this->canSeeChatRoom);

        Flux::toast('Chatraum angelegt.');
    }

    /**
     * Löscht den Verweis auf den Chatraum am Antrag — und NUR den.
     *
     * Reparatur für den Fall, dass der Raum auf dem Relay gelöscht wurde
     * (kind 9008): Der Verein weiß davon nichts, zeigt weiter „Chat öffnen" ins
     * Leere und lässt „Chatraum anlegen" nicht zu, weil createChatRoom einen
     * freien Antrag verlangt. Nach dem Zurücksetzen greift dieses Gate wieder.
     *
     * Auf dem Relay passiert hier nichts: Es wird kein Event publiziert. Die
     * Raum-ID ist aus der Antrags-ID abgeleitet, ein erneutes Anlegen ergibt
     * also dieselbe — existiert der Raum noch, wird er schlicht wieder
     * verknüpft.
     *
     * Die Berechtigung wird hier geprüft, nicht nur an der Sichtbarkeit des
     * Knopfes: Jede öffentliche Livewire-Methode ist direkt aufrufbar.
     */
    public function resetChatRoom(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('resetChatRoom', $this->projectProposal);

        // Direkte Zuweisung wie in storeChatRoom(): nostr_group_h ist bewusst
        // nicht in $fillable.
        $this->projectProposal->nostr_group_h = null;
        $this->projectProposal->nostr_group_created_at = null;
        $this->projectProposal->save();

        unset($this->canCreateChatRoom, $this->canSeeChatRoom, $this->canResetChatRoom);

        Flux::modals()->close();
        Flux::toast('Raumverweis zurückgesetzt. „Chatraum anlegen" steht wieder bereit.');
    }

    /**
     * The section belongs to the board and the submitter, the same people
     * as the contact details (see `canSeeChatSection` on the page).
     */
    private function authorizeSection(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('viewContact', $this->projectProposal);
    }
};
?>

<div class="contents">
    {{-- ── Chat zum Antrag ─────────────────────────────────────────
         Erste Karte der Spalte, auf JEDEM Viewport (`order-first`;
         die Geschwister tragen order-1..5). Fuer den Kreis, der ihn
         ueberhaupt sieht (Vorstand, Einreicher), ist der private
         Antragsraum der Grund, die Seite zu oeffnen.

         Vorher war das ein Band ueber dem ganzen Zweispalter: auf
         Desktop ein Streifen ueber die volle Breite mit 448px
         Verlauf, der den Antrag unter die Falz schob. Jetzt ist der
         Chat so breit wie die Karten daneben (320px, ab xl 384px)
         und der Verlauf auf 320px Hoehe gedeckelt (h-80 xl:h-96 in
         partials/project-chat-feed.blade.php).

         Genau EINE Insel-Instanz — diese hier. Eine zweite bedeutete
         doppelte Alpine-Initialisierung und eine zweite
         Relay-Verbindung.

         Das Aufklappen ist rein optisch: Was die Insel laedt und
         wann, entscheidet weiterhin sie selbst (projectChatFeed.js)
         — weder ein zugeklapptes noch ein aufgeklapptes Panel laedt
         etwas nach, solange keine Chat-Session besteht.

         Das Gate bleibt serverseitig: Wer den Raum nicht sehen darf,
         bekommt dieses Markup gar nicht erst. --}}
    {{-- Die Chat-Vollbildseite liegt auf dem Space-Host, nicht im
         Verein: ws(s):// -> https://.
         Bewusst die einzeilige Form der php-Direktive: Weiter oben
         steht bereits eine einzeilige ohne Abschluss. Blades
         Raw-Block-Regex paart die erste Oeffnung mit dem NAECHSTEN
         Abschluss — ein Block hier verschluckte alles dazwischen, und
         die Datei liesse sich nicht mehr uebersetzen. --}}
    @php($chatClientUrl = rtrim(str_replace(['ws://', 'wss://'], 'https://', config('group.space_url', '')), '/'))
    {{-- Aufklapp-Regel: unter 48rem (md) zu, darueber offen —
         dieselbe Schwelle wie beim Band, aber aus einem anderen
         Grund. Beim Band ging es darum, dass der Antrag nicht
         unter die Falz rutscht; in der Spalte steht der Antrag
         mobil ohnehin davor. Was der zugeklappte Start jetzt
         spart, ist der Weg ZUR ABSTIMMUNG: offen liegen ~480px
         Chat zwischen Beschreibung und „Deine Stimme", zu bleibt
         eine 64px-Zeile.

         Ab `md` offen — nicht erst ab `lg`: Zwischen 768 und
         1024px ist die Spalte zwar noch gestapelt, aber der
         Bildschirm hoch genug, dass der Chat die Abstimmung
         nicht aus dem Blick schiebt. Und auf Desktop MUSS er
         offen starten, sonst ist „Chat als erstes Element" eine
         64px-Zeile und der Umzug sinnlos.

         Ohne Raum ist der Inhalt drei Zeilen lang — der klappt
         auch auf dem Telefon nichts weg und startet offen. --}}
    @php($chatBandInitiallyOpen = $projectProposal->hasNostrGroup() ? "window.matchMedia('(min-width: 48rem)').matches" : 'true')

    {{-- Kartensprache der Spalte (rounded-lg, border-border-default,
         bg-bg-surface) — aber OHNE die orange 2px-Rail des alten
         Bands: In dieser Spalte ist Orange am Rahmen bereits
         vergeben. Das Vorstands-Panel traegt es als „hier gilt
         anderes Recht"; eine zweite orange umrandete Karte direkt
         darueber nimmt dem Signal genau diese Bedeutung. Die
         Zugehoerigkeit zur Marke traegt hier das orange Icon in
         der Kopfzeile, die Unterscheidung von den Nachbarkarten
         die Kopfzeile selbst: die einzige Karte der Spalte, die
         ein Bedienelement IST statt eine Anzeige.

         Die Polsterung ist bewusst px-4 sm:px-6 wie bei den Nachbarn
         (nicht px-4): In einer 320px-Spalte stehen die Karten
         direkt uebereinander, unterschiedliche Textkanten faellt
         dort sofort auf.

         Bewusst KEIN overflow-hidden zum Runden der Kopfzeile:
         Die Insel legt Emoji-Panel, Mention-Liste und
         Aktionsleiste absolut ueber den Verlauf; ein clippender
         Vorfahr schnitte sie ab. Stattdessen rundet die
         Kopfzeile sich selbst. --}}
    <div class="order-first rounded-lg border border-border-default bg-bg-surface"
         x-data="{ open: {{ $chatBandInitiallyOpen }} }">

        {{-- Die ganze Kopfzeile ist der Schalter: ein 44px hohes Ziel
             ueber die volle Kartenbreite. Der Ausweich-Link steht
             bewusst NICHT hier drin — verschachtelte Bedienelemente
             in einem Button sind ungueltig; er sitzt im Panel.

             `aria-expanded:rounded-b-none`: Zugeklappt IST die
             Kopfzeile die ganze Karte, ihre Hover-Flaeche muss dann
             unten mitrunden. Der Zustand kommt aus dem eigenen
             aria-Attribut — keine zweite Wahrheit neben `open`.

             `wire:ignore.self`: Livewires Morph schriebe die Attribute
             des Knopfes sonst auf den Server-Stand zurueck — nach der
             ersten Stimmabgabe meldete `aria-expanded` „false", waehrend
             das Panel offen dasteht. Die Kinder morpht Livewire weiter
             (`childrenOnly`), sie sind rein serverseitig. --}}
        <button type="button" wire:ignore.self
                class="group flex w-full min-h-11 items-center gap-3 rounded-lg aria-expanded:rounded-b-none px-4 sm:px-6 py-3 text-left transition-colors duration-150 hover:bg-bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 motion-reduce:transition-none"
                x-on:click="open = ! open"
                aria-expanded="false"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="chat-band-panel">
            <flux:icon name="chat-bubble-left-right" variant="micro"
                       class="shrink-0 text-orange-500" aria-hidden="true"/>
            <span class="min-w-0 flex-1">
                <span class="block text-base font-semibold leading-tight text-text-primary">
                    Chat zum Antrag
                </span>
                <span class="mt-0.5 block truncate text-sm text-text-tertiary">
                    @if($projectProposal->hasNostrGroup())
                        Privater Raum — nur Vorstand und Einreicher
                    @else
                        Noch kein Raum angelegt
                    @endif
                </span>
            </span>
            {{-- Der Pfeil dreht sich rein per CSS am aria-Zustand des
                 Knopfes — keine zweite Wahrheit neben `open`, und nichts,
                 was ein Morph zuruecksetzen koennte. --}}
            <span class="inline-flex shrink-0 text-text-tertiary transition-transform duration-150 group-aria-expanded:rotate-180 motion-reduce:transition-none">
                <flux:icon name="chevron-down" variant="micro" aria-hidden="true"/>
            </span>
        </button>

        {{-- Zugeklappt kommt als INLINE-Stil vom Server, nicht als Klasse
             und nicht per x-cloak — beide waeren hier falsch:

             `hidden md:block`: x-show setzt beim Oeffnen nur
             `display: ''` zurueck; die Klasse `hidden` bliebe stehen und
             das Panel zu.

             `x-cloak`: Livewires Morph (patchAttributes) uebertraegt die
             Attribute des neuen HTML auf ein VERSTECKTES Element zurueck
             — nach der ersten Stimmabgabe stuende x-cloak wieder da, und
             Alpine entfernt es nur beim Initialisieren. Das Panel liesse
             sich danach nie wieder oeffnen. Ein sichtbares Panel ruehrt
             der Morph nicht an (`_x_isShown`-Weiche), und `display:none`
             ist genau die Eigenschaft, die x-show selbst setzt und
             wegnimmt — der Rueckschlag des Morphs ist damit ein no-op.

             Preis: Bis Alpine laeuft, ist das Panel auf JEDEM Viewport
             zu. Sein Inhalt haengt ohnehin an Alpine. --}}
        <div id="chat-band-panel" x-show="open" style="display: none"
             class="border-t border-border-default px-4 sm:px-6 pb-5">

            @if($projectProposal->hasNostrGroup())
                {{-- Nur noch das Anlegedatum in der Meta-Zeile: Wer
                     Zugang hat, steht schon in der Unterzeile der
                     Kopfzeile („Privater Raum — nur Vorstand und
                     Einreicher"). Im Band waren die beiden Angaben
                     nebeneinander und die Doppelung billig; in einer
                     320px-Spalte kostete sie eine ganze Zeile
                     Chrome ueber dem Verlauf.

                     Die Klassen `flex flex-wrap items-center gap-x-5`
                     bleiben unveraendert — der 320px-Reflow-Test greift
                     diese Zeile ueber genau diese Kette ab. --}}
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 pt-4">
                    @if($projectProposal->nostr_group_created_at)
                        <dl class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                <dt class="sr-only">Angelegt am</dt>
                                <flux:icon name="calendar" variant="micro"
                                           class="shrink-0 text-text-tertiary" aria-hidden="true"/>
                                <dd class="text-text-secondary">
                                    Angelegt am
                                    {{ $projectProposal->nostr_group_created_at->translatedFormat('d.m.Y') }}
                                </dd>
                            </div>
                        </dl>
                    @endif

                    {{-- Harter Full-Load statt wire:navigate: Die Chat-Seite
                         ist eine eigenstaendige Nostr-Insel; ein SPA-Wechsel
                         liefert dort einen toten JS-Kontext. Der Link bleibt
                         auch neben der eingebetteten Ansicht stehen: Die Insel
                         kann scheitern (SDK, Signer, Relay), und ein toter Chat
                         ohne Ausweichweg waere schlechter als einer mit.
                         Der Knopf steht ausserhalb der <dl> — in einer
                         Definitionsliste sind nur dt/dd/div erlaubt.

                         Jetzt auf JEDEM Viewport volle Breite (vorher ab `sm`
                         rechtsbuendig und schmal): In einer 280px breiten
                         Kartenflaeche steht ein 130px-Knopf entweder
                         gequetscht neben dem Datum oder verwaist auf einer
                         eigenen Zeile. Volle Breite ist hier dieselbe
                         Entscheidung wie beim „Chat hier laden"-Knopf
                         direkt darunter. --}}
                    <flux:button
                        class="min-h-11 w-full"
                        size="sm"
                        variant="filled"
                        icon-trailing="arrow-top-right-on-square"
                        href="{{ $chatClientUrl }}/rooms/{{ $projectProposal->nostr_group_h }}"
                        target="_blank"
                    >
                        Chat öffnen
                    </flux:button>
                </div>

                {{-- Eingebettete Raum-Ansicht — hinter demselben Gate wie
                     die Kontaktangabe. canCreateChatRoom allein reicht
                     NICHT: Es geht ums Mitlesen, nicht ums Anlegen. --}}
                @if($this->canSeeChatRoom)
                    {{-- Volle Kartenbreite, bewusst ohne Lesebreiten-Deckel:
                         Zeilen und Eingabefeld des Packages bringen selbst
                         keine Begrenzung mit, sie sind fuer die volle Breite
                         gebaut. In dieser Spalte sind das 280px (ab xl
                         344px) — jeder Deckel darunter waere absurd. --}}
                    <div class="min-w-0">
                        @include('partials.project-chat-feed', [
                            'roomId' => $projectProposal->nostr_group_h,
                            'roomName' => $projectProposal->name,
                            'currentPubkey' => $currentPubkey,
                            'clientUrl' => $chatClientUrl,
                        ])
                    </div>
                @endif

                {{-- Reparaturweg fuer den toten Verweis: Loescht ein
                     Admin den Raum auf dem Relay, bleibt
                     `nostr_group_h` stehen — „Chat oeffnen" zeigt
                     dann ins Leere und „Chatraum anlegen" erscheint
                     nicht, weil createChatRoom einen freien Antrag
                     verlangt. Der Antrag saesse sonst fest.

                     Bewusst leise: eine abgesetzte Fusszeile unter
                     dem Verlauf, `variant="subtle"` und kleiner
                     Text. „Chat oeffnen" oben bleibt der einzige
                     gefuellte Knopf der Karte — das hier ist eine
                     seltene Reparatur, kein Alltagswerkzeug. Volle
                     Breite wie die Nachbarknoepfe, weil daneben in
                     280px nichts mehr Platz hat.

                     Kein x-cloak und kein x-show: Das Markup haengt
                     allein am serverseitigen Gate. Wer nicht im
                     Vorstand ist, bekommt es gar nicht erst. --}}
                @if($this->canResetChatRoom)
                    <div class="mt-4 border-t border-border-default pt-3">
                        <flux:modal.trigger name="confirm-reset-chat-room">
                            <flux:button class="min-h-11 w-full"
                                         size="sm"
                                         variant="subtle"
                                         icon="arrow-path">
                                Raumverweis zurücksetzen
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                @endif
            @elseif($this->canCreateChatRoom)
                {{-- projectChatRoom ist in app.js registriert, laeuft also
                     vor Alpines Start. Das Chat-SDK laedt die Komponente
                     selbst per dynamischem Import beim Klick. --}}
                <div class="pt-4"
                     x-data="projectChatRoom({
                    spaceUrl: @js(config('group.space_url')),
                    roomId: @js($projectProposal->nostrGroupId()),
                    proposalId: @js($projectProposal->id),
                    roomName: @js($projectProposal->slug),
                    roomAbout: @js('Antragsraum'),
                    memberPubkeys: @js($this->chatRoomMemberPubkeys),
                    currentPubkey: @js($currentPubkey),
                })">
                    <p class="text-sm text-text-secondary">
                        Ein privater Raum für die Rückfragen des Vorstands an den
                        Einreicher. Vorstand und Einreicher werden automatisch
                        aufgenommen, sonst sieht ihn niemand.
                    </p>

                    {{-- Die Beschriftung steht fest im Markup und haengt
                         NICHT an Alpine: Steckte sie in einem x-show-Span,
                         waere der Knopf unbeschriftet, sobald die Insel
                         nicht laedt — fuer Screenreader wie fuer Augen. --}}
                    <flux:button
                        class="mt-3 w-full min-h-11"
                        size="sm"
                        variant="primary"
                        icon="chat-bubble-left-right"
                        x-on:click="create()"
                        x-bind:disabled="busy"
                    >
                        Chatraum anlegen
                    </flux:button>

                    <p x-show="progress" x-cloak
                       class="mt-2 text-sm text-text-secondary"
                       x-text="progress"></p>

                    <p x-show="error" x-cloak
                       class="mt-2 text-sm text-danger"
                       x-text="error"></p>
                </div>
            @else
                <p class="pt-4 text-sm text-text-secondary">
                    Der Vorstand legt den Raum bei Nachfragen an. Danach steht er hier.
                </p>
            @endif
        </div>
    </div>

    {{-- The dialog sits out of the flow: as an item of the side column it
         would add the column gap below the card. --}}
    <div class="absolute">
        {{-- Raumverweis zuruecksetzen bestaetigen: Der Knopf sieht aus wie
             „Chat weg", ist es aber nicht. Deshalb sagt der Text beide Haelften
             ausdruecklich — was NICHT passiert (der Raum bleibt), und dass der
             Weg zurueck offen ist. Ohne Fachjargon: der Vorstand liest das,
             nicht ein Entwickler. --}}
        @if($this->canResetChatRoom)
            <flux:modal name="confirm-reset-chat-room" class="min-w-88">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Raumverweis zurücksetzen?</flux:heading>
                        <flux:text class="mt-2">
                            <p>Der Antrag zeigt danach auf keinen Chatraum mehr.</p>
                            <p>
                                Der Chatraum selbst wird <strong>nicht gelöscht</strong> — es wird
                                nur die Verknüpfung hier im Verein entfernt.
                            </p>
                            <p>
                                Du kannst ihn danach mit „Chatraum anlegen" sofort wieder
                                verbinden: Es entsteht derselbe Raum wie vorher.
                            </p>
                        </flux:text>
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer/>
                        <flux:modal.close>
                            <flux:button variant="ghost">Abbrechen</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="resetChatRoom" variant="danger" icon="arrow-path">
                            Zurücksetzen
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
</div>
