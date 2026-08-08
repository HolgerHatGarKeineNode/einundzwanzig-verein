<?php

use App\Enums\ProjectProposalStatus;
use App\Livewire\Traits\WithNostrAuth;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use WithNostrAuth;

    #[Locked]
    public ProjectProposal $projectProposal;

    #[Locked]
    public bool $ownVoteExists = false;

    public ?int $payoutSats = null;

    public function mount(ProjectProposal $projectProposal): void
    {
        $this->projectProposal = $projectProposal;
        $this->payoutSats = $projectProposal->sats_paid ?: $projectProposal->support_in_sats;
        if (NostrAuth::check()) {
            $this->currentPubkey = NostrAuth::pubkey();
            $this->isAllowed = true;
            $this->mountWithNostrAuth();
            // `currentPleb` ist nullable — eine angemeldete Sitzung ohne
            // Mitgliedseintrag ist ein gültiger Zustand (der Eintrag kann nach
            // dem Login entfernt worden sein). `ownVote` unten prüft das bereits;
            // hier fehlte die Prüfung und die Seite antwortete mit einer 500
            // statt mit der Karte, die dem Betrachter erklärt, warum er nicht
            // abstimmen kann.
            $this->ownVoteExists = $this->currentPleb !== null && Vote::query()
                ->where('project_proposal_id', $this->projectProposal->id)
                ->where('einundzwanzig_pleb_id', $this->currentPleb->id)
                ->exists();
        }
    }

    /**
     * Alle Stimmen zum Antrag in EINER Abfrage. Bewusst OHNE Profile der
     * Stimmenden: Wer wie abgestimmt hat, ist nicht öffentlich — die Seite
     * zeigt nur Zahlen, also darf auch nichts Personenbezogenes ins HTML.
     */
    #[Computed]
    public function votes(): Collection
    {
        $boardPlebIds = ProjectProposal::boardPlebIds();

        return Vote::query()
            ->where('project_proposal_id', $this->projectProposal->id)
            ->get()
            ->each(fn (Vote $vote) => $vote->setAttribute(
                'is_board_vote',
                in_array($vote->einundzwanzig_pleb_id, $boardPlebIds, true)
            ));
    }

    #[Computed]
    public function boardVotes(): Collection
    {
        return $this->votes->where('is_board_vote', true);
    }

    #[Computed]
    public function otherVotes(): Collection
    {
        return $this->votes->where('is_board_vote', false);
    }

    /**
     * Der abgeleitete Status des Antrags — dieselbe Regel wie in der Übersicht.
     */
    #[Computed]
    public function status(): ProjectProposalStatus
    {
        $this->projectProposal->setAttribute('board_approvals_count', $this->boardVotes->where('value', true)->count());
        $this->projectProposal->setAttribute('board_rejections_count', $this->boardVotes->where('value', false)->count());

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
     * Ist der Betrachter überhaupt stimmberechtigt? Bewusst NICHT über die
     * create-Policy: die verneint, sobald eine Stimme existiert. Wer schon
     * abgestimmt hat, soll seine Stimme weiterhin sehen — nur eben nicht
     * ändern können.
     */
    #[Computed]
    public function isVoter(): bool
    {
        return NostrAuth::user()?->getPleb() !== null;
    }

    /**
     * Zählt die Stimme des Betrachters zur bindenden Mehrheit — oder ist sie
     * Stimmungsbild? Beide dürfen abstimmen, nur eben mit anderem Gewicht.
     */
    #[Computed]
    public function voteCountsTowardsMajority(): bool
    {
        return (bool) NostrAuth::user()?->getPleb()?->isBoardMember();
    }

    /**
     * Darf jetzt eine Stimme abgegeben werden? Dieselbe Prüfung, die
     * handleApprove() serverseitig macht — damit niemand einen Knopf sieht,
     * der für ihn in einer 403 endet.
     */
    #[Computed]
    public function canVote(): bool
    {
        $nostrUser = NostrAuth::user();

        if (! $nostrUser || ! $nostrUser->getPleb()) {
            return false;
        }

        return Gate::forUser($nostrUser)->allows('create', [Vote::class, $this->projectProposal]);
    }

    /**
     * Die eigene Stimme, sofern abgegeben — damit die Seite sagen kann, WIE
     * abgestimmt wurde, statt nur dass abgestimmt wurde.
     */
    #[Computed]
    public function ownVote(): ?Vote
    {
        if (! $this->currentPleb) {
            return null;
        }

        return $this->votes->firstWhere('einundzwanzig_pleb_id', $this->currentPleb->id);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('accept', $this->projectProposal);
    }

    /**
     * Darf jetzt ausgezahlt werden? Dieselbe Prüfung, die recordPayout()
     * serverseitig macht — die Oberfläche zeigt das Formular sonst für einen
     * Antrag, den niemand auszahlen darf.
     */
    #[Computed]
    public function canPayout(): bool
    {
        return Gate::forUser(NostrAuth::user())->allows('payout', $this->projectProposal);
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
     * Trägt die Auszahlung ein. Nur Vorstand — die Berechtigung wird hier
     * serverseitig geprüft, weil jede öffentliche Livewire-Methode direkt
     * aufrufbar ist, unabhängig davon, was die View rendert.
     */
    public function recordPayout(): void
    {
        // 'payout' statt 'accept': verlangt zusätzlich die absolute Mehrheit des
        // Vorstands. Geld darf nur einem Beschluss folgen — ohne diese Prüfung
        // könnte ein einzelnes Vorstandsmitglied einen noch laufenden oder sogar
        // abgelehnten Antrag auszahlen.
        Gate::forUser(NostrAuth::user())->authorize('payout', $this->projectProposal);

        $this->validate([
            'payoutSats' => 'required|integer|min:1',
        ], [
            'payoutSats.min' => 'Der ausgezahlte Betrag muss größer als 0 sein.',
        ]);

        // Direkte Zuweisung statt update(): sats_paid ist bewusst NICHT in $fillable,
        // damit eine Auszahlung nie über Mass Assignment gesetzt werden kann.
        $this->projectProposal->sats_paid = (int) $this->payoutSats;
        $this->projectProposal->save();

        $this->forgetVoteCache();
        Flux::modals()->close();
        Flux::toast('Auszahlung eingetragen.');
    }

    /**
     * Nimmt eine eingetragene Auszahlung zurück (Korrektur einer Fehleingabe).
     */
    public function revertPayout(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('accept', $this->projectProposal);

        $this->projectProposal->sats_paid = 0;
        $this->projectProposal->save();
        $this->payoutSats = $this->projectProposal->support_in_sats;

        $this->forgetVoteCache();
        Flux::toast('Auszahlung zurückgenommen.');
    }

    private function forgetVoteCache(): void
    {
        unset($this->votes, $this->boardVotes, $this->otherVotes, $this->status, $this->ownVote);
    }

    public function handleApprove(): void
    {
        $nostrUser = NostrAuth::user();

        if (! $nostrUser || ! $nostrUser->getPleb()) {
            return;
        }

        Gate::forUser($nostrUser)->authorize('create', [Vote::class, $this->projectProposal]);

        $executed = RateLimiter::attempt(
            'voting:'.request()->ip(),
            10,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many voting attempts.');
        }

        Vote::query()->updateOrCreate([
            'project_proposal_id' => $this->projectProposal->id,
            'einundzwanzig_pleb_id' => $this->currentPleb->id,
        ], [
            'value' => true,
        ]);
        $this->ownVoteExists = true;
        $this->forgetVoteCache();
        Flux::modals()->close();
        Flux::toast('Deine Stimme wurde gezählt.');
    }

    public function handleNotApprove(): void
    {
        $nostrUser = NostrAuth::user();

        if (! $nostrUser || ! $nostrUser->getPleb()) {
            return;
        }

        Gate::forUser($nostrUser)->authorize('create', [Vote::class, $this->projectProposal]);

        $executed = RateLimiter::attempt(
            'voting:'.request()->ip(),
            10,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many voting attempts.');
        }

        Vote::query()->updateOrCreate([
            'project_proposal_id' => $this->projectProposal->id,
            'einundzwanzig_pleb_id' => $this->currentPleb->id,
        ], [
            'value' => false,
        ]);
        $this->ownVoteExists = true;
        $this->forgetVoteCache();
        Flux::modals()->close();
        Flux::toast('Deine Stimme wurde gezählt.');
    }
}
?>

<div>
    @php
        $threshold = App\Models\ProjectProposal::boardVoteThreshold();
        $approvals = $this->boardVotes->where('value', true);
        $rejections = $this->boardVotes->where('value', false);
        $otherApprovals = $this->otherVotes->where('value', true);
        $otherRejections = $this->otherVotes->where('value', false);
        $leading = $rejections->count() > $approvals->count() ? 'rejected' : 'approved';
        $filled = min($leading === 'rejected' ? $rejections->count() : $approvals->count(), $threshold);
    @endphp

    <div class="w-full max-w-[1600px] mx-auto">
        <div class="mb-6">
            <flux:button :href="route('association.projectSupport')" variant="ghost" size="sm" icon="chevron-left">
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
        <div class="flex flex-col lg:flex-row lg:gap-8 xl:gap-12">
            {{-- Hauptspalte --}}
            <div class="flex-1 min-w-0">
                <span class="status-chip status-chip--{{ $this->status->value }}">
                    <flux:icon :name="$this->status->icon()" variant="micro" aria-hidden="true"/>
                    {{ $this->status->label() }}
                </span>

                <h1 class="mt-3 mb-4 max-w-[68ch] text-2xl md:text-[28px] font-bold tracking-tight leading-[1.15] text-text-primary">
                    {{ $projectProposal->name }}
                </h1>

                {{-- Eckdaten --}}
                <div class="mb-6 rounded-xl border border-border-subtle bg-bg-surface p-5">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">Fördersumme</dt>
                            <dd class="mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-orange-500">
                                {{ number_format($projectProposal->support_in_sats, 0, ',', '.') }} Sats
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">Eingereicht am</dt>
                            <dd class="mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-text-secondary">
                                {{ $projectProposal->created_at->translatedFormat('d.m.Y') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">Eingereicht von</dt>
                            <dd class="mt-1 flex items-center gap-2 text-sm">
                                <img class="size-6 rounded-full shrink-0"
                                     src="{{ $projectProposal->einundzwanzigPleb->profile?->picture }}"
                                     onerror="this.src='{{ asset('einundzwanzig-alpha.jpg') }}'"
                                     alt="" width="24" height="24" loading="lazy">
                                <a href="https://njump.me/{{ $projectProposal->einundzwanzigPleb->npub }}"
                                   target="_blank" rel="noopener"
                                   class="truncate text-text-secondary hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                    {{ $projectProposal->einundzwanzigPleb->profile?->name ?? str($projectProposal->einundzwanzigPleb->npub)->limit(24) }}
                                </a>
                            </dd>
                        </div>
                        @if($projectProposal->website)
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">Webseite</dt>
                                <dd class="mt-1 text-sm">
                                    <a href="{{ $projectProposal->website }}" target="_blank" rel="noopener"
                                       class="break-all text-text-secondary hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
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

                <div class="prose dark:prose-invert max-w-[68ch] break-words [&_code]:break-all [&_a]:break-all">
                    {!! $projectProposal->description !!}
                </div>
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
                @if($this->canSeeChatSection)
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

                    {{-- Kartensprache der Spalte (rounded-xl, border-border-subtle,
                         bg-bg-surface) — aber OHNE die orange 2px-Rail des alten
                         Bands: In dieser Spalte ist Orange am Rahmen bereits
                         vergeben. Das Vorstands-Panel traegt es als „hier gilt
                         anderes Recht"; eine zweite orange umrandete Karte direkt
                         darueber nimmt dem Signal genau diese Bedeutung. Die
                         Zugehoerigkeit zur Marke traegt hier das orange Icon in
                         der Kopfzeile, die Unterscheidung von den Nachbarkarten
                         die Kopfzeile selbst: die einzige Karte der Spalte, die
                         ein Bedienelement IST statt eine Anzeige.

                         Die Polsterung ist bewusst px-5 wie bei den Nachbarn
                         (nicht px-4): In einer 320px-Spalte stehen die Karten
                         direkt uebereinander, unterschiedliche Textkanten faellt
                         dort sofort auf.

                         Bewusst KEIN overflow-hidden zum Runden der Kopfzeile:
                         Die Insel legt Emoji-Panel, Mention-Liste und
                         Aktionsleiste absolut ueber den Verlauf; ein clippender
                         Vorfahr schnitte sie ab. Stattdessen rundet die
                         Kopfzeile sich selbst. --}}
                    <div class="order-first rounded-xl border border-border-subtle bg-bg-surface"
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
                                class="group flex w-full min-h-11 items-center gap-3 rounded-xl aria-expanded:rounded-b-none px-5 py-3 text-left transition-colors duration-150 hover:bg-bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 motion-reduce:transition-none"
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
                             class="border-t border-border-subtle px-5 pb-5">

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
                                    <div class="mt-4 border-t border-border-subtle pt-3">
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
                                       class="mt-2 text-sm text-red-400"
                                       x-text="error"></p>
                                </div>
                            @else
                                <p class="pt-4 text-sm text-text-secondary">
                                    Der Vorstand legt den Raum bei Nachfragen an. Danach steht er hier.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Eigene Stimme: steht JEDEM stimmberechtigten Mitglied offen,
                     nicht nur dem Vorstand — deshalb ein neutrales Panel und
                     nicht der orange Vorstands-Rahmen.

                     Erste Karte fuer JEDES Mitglied, das nur abstimmt: Sie ist
                     der einzige Grund, warum es diese Seite bedienen muss. Nur
                     Vorstand und Einreicher haben den Chat davor — und fuer die
                     beiden ist der Antragsraum der Grund zu kommen. --}}
                @if($this->isVoter)
                    <div class="order-1 rounded-xl border border-border-subtle bg-bg-surface p-5">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary mb-1">
                            Deine Stimme
                        </div>
                        {{-- Beide dürfen abstimmen, nur mit anderem Gewicht — das
                             muss dranstehen, sonst hält ein Mitglied seine Stimme
                             für bindend oder gibt sie gar nicht erst ab. --}}
                        <p class="mb-3 text-sm text-text-tertiary">
                            @if($this->voteCountsTowardsMajority)
                                Zählt zur bindenden Mehrheit des Vorstands.
                            @else
                                Zählt zum Stimmungsbild der Mitglieder, nicht zur Mehrheit.
                            @endif
                        </p>

                        @if($this->ownVote)
                            {{-- Abgegebene Stimmen sind endgültig. Die Seite sagt jetzt
                                 WIE abgestimmt wurde, statt nur DASS abgestimmt wurde. --}}
                            <p @class([
                                'flex items-center gap-2 text-sm',
                                'text-green-500' => (bool) $this->ownVote->value,
                                'text-red-400' => ! (bool) $this->ownVote->value,
                            ])>
                                <flux:icon :name="$this->ownVote->value ? 'check-circle' : 'x-circle'"
                                           variant="micro" aria-hidden="true"/>
                                Du hast {{ $this->ownVote->value ? 'zugestimmt' : 'abgelehnt' }}.
                            </p>
                            <p class="mt-1 text-sm text-text-tertiary">
                                Eine abgegebene Stimme lässt sich nicht mehr ändern.
                            </p>
                        @elseif($this->canVote)
                            <div class="flex gap-2">
                                <flux:modal.trigger name="confirm-approve">
                                    <flux:button variant="primary" icon="hand-thumb-up" class="flex-1 min-h-11">
                                        Zustimmen
                                    </flux:button>
                                </flux:modal.trigger>
                                <flux:modal.trigger name="confirm-reject">
                                    <flux:button variant="danger" icon="hand-thumb-down" class="flex-1 min-h-11">
                                        Ablehnen
                                    </flux:button>
                                </flux:modal.trigger>
                            </div>
                            <p class="mt-2 text-sm text-text-tertiary">
                                Deine Stimme ist endgültig.
                            </p>
                        @endif
                    </div>
                @else
                    {{-- Ersatzzustand derselben Karte — die Seite darf an dieser
                         Stelle nicht schweigen.

                         Die Route ist oeffentlich, die Karte hing allein am Login:
                         Wer ohne Anmeldung kam (auf dem Telefon der Normalfall, dort
                         gibt es keine Signer-Erweiterung), sah die ganze Seite, aber
                         die Abstimmung fehlte ERSATZLOS. Nichts auf der Seite sagte,
                         dass es sie ueberhaupt gibt oder wie man an sie kommt — das
                         liest sich als „hier darf niemand abstimmen", nicht als
                         „melde dich an". Gemeldet aus dem Verein, live nachgestellt.

                         `order-1` wie die Karte, die hier sonst steht: Der Ersatz
                         nimmt exakt denselben Platz in der Spalte ein. Wer sich
                         anmeldet, sieht danach dieselbe Box an derselben Stelle mit
                         Bedienelementen — keine Karte, die woanders auftaucht. Auf
                         dem Telefon haelt `order-1` sie ausserdem VOR den beiden
                         Zaehlkarten (order-2/order-3): Wie andere abgestimmt haben,
                         ist erst interessant, wenn man weiss, ob und wie man selbst
                         mitreden kann. Ohne die Klasse fiele sie ans Ende der Spalte,
                         also hinter genau diese Zaehlkarten.

                         Auch die Beschriftung bleibt „Deine Stimme". Der Slot behaelt
                         seine Identitaet ueber alle Anmeldezustaende hinweg; eine
                         eigene Ueberschrift wie „Anmeldung noetig" waere eine zweite
                         Vokabel fuer dieselbe Sache. --}}
                    <div class="order-1 rounded-xl border border-border-subtle bg-bg-surface p-5">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary mb-1">
                            Deine Stimme
                        </div>

                        @if(NostrAuth::check())
                            {{-- Angemeldet, aber ohne Mitgliedseintrag. Selten, aber
                                 erreichbar: die Sitzung haelt nur den Pubkey, der
                                 Eintrag kann danach entfernt worden sein.

                                 Bewusst der Warnhinweis-Aufbau der Karte „Kein
                                 Kontaktweg hinterlegt." weiter unten (Icon +
                                 Klartextzeile + Erklaerung) statt eines
                                 flux:callout: In dieser Spalte ist die Karte das
                                 Layout-Element, ein Callout braechte eine zweite
                                 Rahmensprache mit.

                                 Der Wortlaut nennt bewusst NICHT „aktives Mitglied +
                                 bezahlte Mitgliedschaft" wie die Callouts in
                                 news.blade.php und form/create.blade.php: Abstimmen
                                 verlangt das nicht. VotePolicy::create prueft
                                 ausschliesslich, ob ein Mitgliedseintrag existiert
                                 und noch keine Stimme abgegeben wurde — eine
                                 unbezahlte Mitgliedschaft stimmt hier mit ab
                                 (Stimmungsbild). Ein Hinweis auf den Beitrag
                                 schickte den Betrachter also einer Bedingung
                                 hinterher, die es fuer diese Karte nicht gibt. --}}
                            <div class="flex items-start gap-2">
                                <flux:icon name="exclamation-triangle" variant="micro" class="mt-1 shrink-0 text-yellow-400" aria-hidden="true"/>
                                <div>
                                    <p class="text-sm text-text-primary">
                                        Zu deinem Schlüssel ist kein Mitgliedseintrag hinterlegt.
                                    </p>
                                    <p class="mt-1 text-sm text-text-secondary">
                                        Abstimmen kann nur, wer im Verein als Mitglied geführt wird.
                                        Bitte kontaktiere den Vorstand, wenn du denkst, dass du berechtigt sein solltest.
                                    </p>
                                </div>
                            </div>
                        @else
                            <p class="mb-3 text-sm text-text-secondary">
                                Mit einer Nostr-Anmeldung kannst du diesem Antrag zustimmen oder ihn ablehnen.
                            </p>

                            {{-- Zweite Instanz von auth-button, nicht nur ein Verweis
                                 aufs Menue. Der Ausloeser gehoert dorthin, wo der
                                 Grund steht — sonst schickt die Karte den Handy-Nutzer
                                 auf die Suche nach genau dem Knopf, den sie ihm
                                 gerade erklaert hat.

                                 Unschaedlich, weil die Komponente auf Mehrfach-Mounts
                                 ausgelegt IST: `mount()` zieht die Challenge ueber
                                 NostrAuth::currentOrIssueChallenge(), deren Docblock
                                 genau diesen Fall beschreibt (Sidebar + Navbar auf
                                 derselben Seite) — beide Instanzen rendern denselben
                                 Sitzungswert, statt sich gegenseitig zu ueberschreiben.
                                 Die IDs des Fortschritts-Overlays haengen am
                                 `location`-Parameter, deshalb hier ein eigener Wert.
                                 `nostrLoginInProgress` ist Zustand der EINEN
                                 Alpine-Insel, also zeigt nur die geklickte Instanz ihr
                                 Overlay. Und dass das `nostrLoggedIn`-Ereignis
                                 mehrere Livewire-Hoerer erreicht, ist bereits heute so
                                 (diese Seite hoert ueber WithNostrAuth mit);
                                 NostrAuth::loginWithSignedEvent ist dafuer
                                 ausdruecklich idempotent.

                                 `[&_button]`: Die Breite laesst sich nicht als Klasse
                                 durchreichen, die Komponente nimmt nur `location`.
                                 Der einzige Knopf im ausgeloggten Zustand ist der
                                 Anmelde-Knopf; volle Breite und 44px Hoehe wie bei
                                 den Nachbarkarten. --}}
                            <div class="[&_button]:w-full [&_button]:min-h-11">
                                <livewire:auth-button location="project-vote" :key="'auth-button-project-vote'"/>
                            </div>

                            {{-- Der eigentliche Grund fuer die Meldung: Auf dem Handy
                                 endet „melde dich an" ohne diesen Satz im Nichts.
                                 Bewusst UNTER dem Knopf und in Tertiaerfarbe — wer
                                 einen Signer im Browser hat, braucht ihn nicht, und
                                 der Dialog zeigt den QR-Code ohnehin selbst. --}}
                            <p class="mt-3 border-t border-border-subtle pt-3 text-sm text-text-tertiary">
                                Auf dem Handy gibt es meist keine Browser-Erweiterung. Der Anmelde-Dialog
                                nimmt dort auch einen bunker://-Link entgegen oder zeigt einen QR-Code
                                zum Scannen mit deiner Signer-App.
                            </p>
                        @endif
                    </div>
                @endif

                {{-- Vorstands-Panel: ausschliesslich fuer den Vorstand. Der orange
                     Rahmen bedeutet "hier gilt anderes Recht" und darf deshalb nicht
                     ueber Aktionen stehen, die jedem Mitglied offenstehen.

                     Auf dem Telefon (`order-4`) rutscht es HINTER die beiden
                     Zaehlkarten: Es ist eine Handlungsflaeche fuer eine Handvoll
                     Menschen, und die Handlung — Auszahlung erfassen — setzt genau
                     das voraus, was die Zaehlkarten zeigen (Quorum erreicht?). Erst
                     der Stand, dann der Griff zum Geld. Auf `lg` steht es wie bisher
                     an zweiter Stelle; dort kostet die Reihenfolge keinen Scroll. --}}
                @if($this->canManage)
                    <div class="order-4 lg:order-2 rounded-xl border border-orange-700 bg-bg-surface overflow-hidden">
                        <div class="flex items-center gap-2 border-b border-orange-700 bg-orange-500/10 px-5 py-3">
                            <flux:icon name="shield-check" variant="micro" class="text-orange-500" aria-hidden="true"/>
                            <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-orange-500">Vorstand</span>
                        </div>

                        <div class="divide-y divide-border-subtle">
                            {{-- (1) Stand --}}
                            <div class="p-5">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">Quorum</span>
                                    <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-primary">
                                        {{ $filled }}/{{ $threshold }}
                                    </span>
                                </div>
                                <div class="h-1.5 rounded-full bg-neutral-700 overflow-hidden"
                                     role="img"
                                     aria-label="{{ $filled }} von {{ $threshold }} nötigen Vorstandsstimmen {{ $leading === 'rejected' ? 'gegen' : 'für' }} das Projekt">
                                    <div @class([
                                            'h-full rounded-full',
                                            'bg-red-400' => $leading === 'rejected',
                                            'bg-orange-500' => $leading !== 'rejected',
                                         ])
                                         style="width: {{ $threshold > 0 ? round($filled / $threshold * 100) : 0 }}%"></div>
                                </div>
                                <p class="mt-2 text-sm text-text-secondary">
                                    @if($this->status === ProjectProposalStatus::Supported)
                                        Ausgezahlt — der Antrag ist abgeschlossen.
                                    @elseif($this->status === ProjectProposalStatus::Rejected)
                                        Der Vorstand hat den Antrag abgelehnt.
                                    @elseif($this->status === ProjectProposalStatus::Accepted)
                                        Angenommen. Es fehlt noch die Auszahlung.
                                    @else
                                        @php($missing = $threshold - $filled)
                                        Es {{ $missing === 1 ? 'fehlt' : 'fehlen' }} {{ $missing }}
                                        {{ $missing === 1 ? 'Stimme' : 'Stimmen' }} zur absoluten Mehrheit
                                        von {{ App\Models\ProjectProposal::boardSize() }} Mitgliedern.
                                    @endif
                                </p>
                            </div>

                            {{-- (3) Abschliessen --}}
                            @if($this->canManage)
                                <div class="p-5">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary mb-3">
                                        Auszahlung
                                    </div>

                                    @if($projectProposal->sats_paid > 0)
                                        <p class="flex items-center gap-2 text-sm text-green-500">
                                            <flux:icon name="check-circle" variant="micro" aria-hidden="true"/>
                                            {{ number_format($projectProposal->sats_paid, 0, ',', '.') }} Sats erfasst.
                                        </p>
                                        <flux:button wire:click="revertPayout" variant="subtle" size="sm" class="mt-3 min-h-11">
                                            Korrigieren
                                        </flux:button>
                                    @elseif($this->canPayout)
                                        <div class="flex items-end gap-2">
                                            <flux:input type="number" wire:model="payoutSats" label="Sats" class="flex-1"/>
                                            <flux:modal.trigger name="confirm-payout">
                                                <flux:button variant="primary" class="min-h-11">Erfassen</flux:button>
                                            </flux:modal.trigger>
                                        </div>
                                        <flux:error name="payoutSats"/>
                                        <p class="mt-2 text-sm text-text-secondary">Noch nichts ausgezahlt.</p>
                                    @else
                                        {{-- Kein Formular ohne Beschluss: Geld folgt der Mehrheit,
                                             nicht dem Vorstandsstatus des Klickenden. --}}
                                        <p class="flex items-start gap-2 text-sm text-text-secondary">
                                            <flux:icon name="lock-closed" variant="micro" class="mt-0.5 shrink-0 text-text-tertiary" aria-hidden="true"/>
                                            <span>
                                                @if($this->status === ProjectProposalStatus::Rejected)
                                                    Der Antrag wurde vom Vorstand abgelehnt und kann nicht ausgezahlt werden.
                                                @else
                                                    @php($fehlend = App\Models\ProjectProposal::boardVoteThreshold() - $this->boardVotes->where('value', true)->count())
                                                    Auszahlung erst nach Beschluss: Es
                                                    {{ $fehlend === 1 ? 'fehlt' : 'fehlen' }} noch {{ $fehlend }}
                                                    {{ $fehlend === 1 ? 'Zustimmung' : 'Zustimmungen' }} zur absoluten Mehrheit.
                                                @endif
                                            </span>
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Vorstandsentscheidung: die Stimmen, die zur Mehrheit zählen --}}
                <div class="order-2 lg:order-3 rounded-xl border border-border-subtle bg-bg-surface p-5">
                    <div class="flex items-baseline justify-between gap-2 mb-3">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                            Vorstandsentscheidung
                        </span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                            {{ $filled }}/{{ $threshold }}
                        </span>
                    </div>
                    <x-vote-tally label="Zustimmung" :votes="$approvals" tone="approve"/>
                    <x-vote-tally label="Ablehnung" :votes="$rejections" tone="reject"/>
                    <p class="mt-3 text-sm text-text-tertiary">
                        Bindend ist die absolute Mehrheit von
                        {{ App\Models\ProjectProposal::boardSize() }} Vorstandsmitgliedern.
                    </p>
                </div>

                {{-- Stimmungsbild: bewusst IMMER sichtbar, auch ohne Stimmen. Vorher
                     hing der Block an otherVotes->isNotEmpty() und verschwand
                     komplett — das las sich, als könnten Mitglieder nicht abstimmen. --}}
                <div class="order-3 lg:order-4 rounded-xl border border-border-subtle bg-bg-surface p-5">
                    <div class="flex items-baseline justify-between gap-2 mb-1">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                            Stimmungsbild der Mitglieder
                        </span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                            {{ $this->otherVotes->count() }}
                        </span>
                    </div>
                    <p class="mb-3 text-sm text-text-tertiary">
                        Zählt nicht zur Mehrheit — es zeigt dem Vorstand, wie der Verein zum Antrag steht.
                    </p>

                    @if($this->otherVotes->isNotEmpty())
                        <x-vote-tally label="Zustimmung" :votes="$otherApprovals" tone="approve"/>
                        <x-vote-tally label="Ablehnung" :votes="$otherRejections" tone="reject"/>
                    @else
                        <p class="text-sm text-text-secondary">
                            Noch keine Stimme abgegeben.
                        </p>
                    @endif
                </div>

                {{-- Kontakt: nur Vorstand und Einreicher. Letzte Karte auf jedem
                     Viewport — Nachschlagewerk, keine Handlung. --}}
                @if($this->canSeeContact)
                    <div class="order-5 rounded-xl border border-border-subtle bg-bg-surface p-5">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary mb-3">
                            Kontakt zum Einreicher
                        </div>
                        @if($projectProposal->contact_via_nostr_dm)
                            <div class="flex items-start gap-2">
                                <flux:icon name="envelope" variant="micro" class="mt-1 shrink-0 text-text-tertiary" aria-hidden="true"/>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-text-secondary">Nostr-DM erwünscht</p>
                                    <x-copy-value
                                        class="mt-1"
                                        :value="$projectProposal->einundzwanzigPleb->npub"
                                        label="npub"
                                    />
                                </div>
                            </div>
                        @elseif($projectProposal->contact_alternative)
                            <div class="flex items-start gap-2">
                                <flux:icon name="envelope" variant="micro" class="mt-1 shrink-0 text-text-tertiary" aria-hidden="true"/>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-text-secondary">Bevorzugter Kanal</p>
                                    <x-copy-value
                                        class="mt-1"
                                        :value="$projectProposal->contact_alternative"
                                        label="Kontaktangabe"
                                    />
                                </div>
                            </div>
                        @else
                            <div class="flex items-start gap-2">
                                <flux:icon name="exclamation-triangle" variant="micro" class="mt-1 shrink-0 text-yellow-400" aria-hidden="true"/>
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

        {{-- Stimmabgabe bestaetigen: sie ist endgueltig, also darf sie kein
             Fehlklick sein. --}}
        @if($this->canVote)
            <flux:modal name="confirm-approve" class="min-w-88">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Dem Projekt zustimmen?</flux:heading>
                        <flux:text class="mt-2">
                            <p>Du stimmst „{{ $projectProposal->name }}" zu.</p>
                            <p>Deine Stimme ist endgültig und kann nicht zurückgenommen werden.</p>
                        </flux:text>
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer/>
                        <flux:modal.close>
                            <flux:button variant="ghost">Abbrechen</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="handleApprove" variant="primary" icon="hand-thumb-up">
                            Zustimmen
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

            <flux:modal name="confirm-reject" class="min-w-88">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Projekt ablehnen?</flux:heading>
                        <flux:text class="mt-2">
                            <p>Du lehnst „{{ $projectProposal->name }}" ab.</p>
                            <p>Deine Stimme ist endgültig und kann nicht zurückgenommen werden.</p>
                        </flux:text>
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer/>
                        <flux:modal.close>
                            <flux:button variant="ghost">Abbrechen</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="handleNotApprove" variant="danger" icon="hand-thumb-down">
                            Ablehnen
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        {{-- Auszahlung bestaetigen: der einzige nicht triviale Schritt --}}
        @if($this->canManage)
            <flux:modal name="confirm-payout" class="min-w-88">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Auszahlung erfassen?</flux:heading>
                        <flux:text class="mt-2">
                            <p>{{ number_format((int) $payoutSats, 0, ',', '.') }} Sats als ausgezahlt erfassen?</p>
                            <p>Das Projekt gilt danach als unterstützt.</p>
                        </flux:text>
                    </div>
                    <div class="flex gap-2">
                        <flux:spacer/>
                        <flux:modal.close>
                            <flux:button variant="ghost">Abbrechen</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="recordPayout" variant="primary">Auszahlung erfassen</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

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
