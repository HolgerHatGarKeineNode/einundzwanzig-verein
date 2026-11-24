<?php

use App\Enums\ProjectProposalStatus;
use App\Livewire\Traits\WithViewer;
use App\Models\EinundzwanzigPleb;
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

/**
 * The votes on a project proposal: the viewer's own vote, the board panel
 * with the quorum and the payout, the board decision and the members'
 * opinion. Counts only — who voted how is not public.
 *
 * A child of `association.project-support.show`. Its root is
 * `display: contents`, so its cards stay items of the page's side column
 * and keep their `order` there. After a vote or a payout it tells the page
 * `project-proposal-changed`, so the status badge follows.
 *
 * Callable on its own like every Livewire component: each write asks its
 * own policy, and a vote is cast for the member the page was rendered for
 * only (`actingPleb()`).
 */
new class extends Component
{
    use WithViewer;

    #[Locked]
    public ProjectProposal $projectProposal;

    /**
     * The member the panel was rendered for. Only what the panel shows reads
     * it; a write asks the session again (`actingPleb()`).
     */
    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    #[Locked]
    public bool $ownVoteExists = false;

    public ?int $payoutSats = null;

    public function mount(ProjectProposal $projectProposal): void
    {
        $this->projectProposal = $projectProposal;
        $this->payoutSats = $projectProposal->sats_paid ?: $projectProposal->approvedSats();
        if (NostrAuth::check()) {
            $this->currentPleb = $this->viewer();
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
     * Ist der Betrachter überhaupt stimmberechtigt? Bewusst NICHT über die
     * create-Policy: die verneint, sobald eine Stimme existiert. Wer schon
     * abgestimmt hat, soll seine Stimme weiterhin sehen — nur eben nicht
     * ändern können.
     */
    #[Computed]
    public function isVoter(): bool
    {
        return $this->viewer() !== null;
    }

    /**
     * Zählt die Stimme des Betrachters zur bindenden Mehrheit — oder ist sie
     * Stimmungsbild? Beide dürfen abstimmen, nur eben mit anderem Gewicht.
     */
    #[Computed]
    public function voteCountsTowardsMajority(): bool
    {
        return $this->viewerIsBoard();
    }

    /**
     * Darf jetzt eine Stimme abgegeben werden? Dieselbe Prüfung, die
     * castVote() serverseitig macht — damit niemand einen Knopf sieht,
     * der für ihn in einer 403 endet.
     */
    #[Computed]
    public function canVote(): bool
    {
        return $this->viewer() !== null && $this->viewerCan('create', [Vote::class, $this->projectProposal]);
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

        // Capped at the amount the board approved: a later edit of the
        // requested amount must not raise what is paid out.
        $approvedSats = $this->projectProposal->approvedSats();

        $this->validate([
            'payoutSats' => 'required|integer|min:1|max:'.$approvedSats,
        ], [
            'payoutSats.min' => 'Der ausgezahlte Betrag muss größer als 0 sein.',
            'payoutSats.max' => 'Der ausgezahlte Betrag darf den beschlossenen Betrag von '.number_format($approvedSats, 0, ',', '.').' Sats nicht übersteigen.',
        ]);

        // Direkte Zuweisung statt update(): sats_paid ist bewusst NICHT in $fillable,
        // damit eine Auszahlung nie über Mass Assignment gesetzt werden kann.
        $this->projectProposal->sats_paid = (int) $this->payoutSats;
        $this->projectProposal->save();

        $this->changed();
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
        $this->payoutSats = $this->projectProposal->approvedSats();

        $this->changed();
        Flux::toast('Auszahlung zurückgenommen.');
    }

    private function forgetVoteCache(): void
    {
        unset($this->votes, $this->boardVotes, $this->otherVotes, $this->status, $this->ownVote);
    }

    /**
     * The viewer's vote on the proposal, for or against. A guest's call does
     * nothing; anyone else needs the vote policy and must still be the member
     * the page was rendered for. An approval freezes the approved amount once
     * the board majority is reached.
     */
    public function castVote(bool $approve): void
    {
        if ($this->viewer() === null) {
            return;
        }

        $this->authorizeViewer('create', [Vote::class, $this->projectProposal]);

        $voter = $this->actingPleb();

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
            'einundzwanzig_pleb_id' => $voter->id,
        ], [
            'value' => $approve,
        ]);

        if ($approve) {
            $this->projectProposal->freezeApprovedSatsOnAcceptance();
        }

        $this->ownVoteExists = true;
        $this->changed();
        Flux::modals()->close();
        Flux::toast('Deine Stimme wurde gezählt.');
    }

    /**
     * Votes and payouts change the status the page shows in its header.
     */
    private function changed(): void
    {
        $this->forgetVoteCache();
        $this->dispatch('project-proposal-changed');
    }
};
?>

<div class="contents">
    @php
        $threshold = App\Models\ProjectProposal::boardVoteThreshold();
        $approvals = $this->boardVotes->where('value', true);
        $rejections = $this->boardVotes->where('value', false);
        $otherApprovals = $this->otherVotes->where('value', true);
        $otherRejections = $this->otherVotes->where('value', false);
        $leading = $rejections->count() > $approvals->count() ? 'rejected' : 'approved';
        $filled = min($leading === 'rejected' ? $rejections->count() : $approvals->count(), $threshold);
    @endphp

    {{-- Eigene Stimme: steht JEDEM stimmberechtigten Mitglied offen,
         nicht nur dem Vorstand — deshalb ein neutrales Panel und
         nicht der orange Vorstands-Rahmen.

         Erste Karte fuer JEDES Mitglied, das nur abstimmt: Sie ist
         der einzige Grund, warum es diese Seite bedienen muss. Nur
         Vorstand und Einreicher haben den Chat davor — und fuer die
         beiden ist der Antragsraum der Grund zu kommen. --}}
    @if($this->isVoter)
        <div class="order-1 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
            <h2 class="text-base font-semibold text-text-primary mb-1">
                Deine Stimme
            </h2>
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
                    'text-success' => (bool) $this->ownVote->value,
                    'text-danger' => ! (bool) $this->ownVote->value,
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
        <div class="order-1 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
            <h2 class="text-base font-semibold text-text-primary mb-1">
                Deine Stimme
            </h2>

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
                    <flux:icon name="exclamation-triangle" variant="micro" class="mt-1 shrink-0 text-warning" aria-hidden="true"/>
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
                     Overlay. The `nostrLoggedIn` event reaches both
                     auth-button instances; only the one with the
                     matching `location` answers, and it reloads the page.

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
                <p class="mt-3 border-t border-border-default pt-3 text-sm text-text-tertiary">
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
        <div class="order-4 lg:order-2 rounded-lg border border-orange-700 bg-bg-surface overflow-hidden">
            <div class="flex items-center gap-2 border-b border-orange-700 bg-orange-500/10 px-4 sm:px-6 py-3">
                <flux:icon name="shield-check" variant="micro" class="text-orange-500" aria-hidden="true"/>
                <span class="text-sm font-semibold text-orange-500">Vorstand</span>
            </div>

            <div class="divide-y divide-border-default">
                {{-- (1) Stand --}}
                <div class="p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="text-sm font-semibold text-text-tertiary">Quorum</span>
                        <span class="text-sm font-semibold text-text-primary">
                            {{ $filled }}/{{ $threshold }}
                        </span>
                    </div>
                    <div class="h-1.5 rounded-full bg-neutral-700 overflow-hidden"
                         role="img"
                         aria-label="{{ $filled }} von {{ $threshold }} nötigen Vorstandsstimmen {{ $leading === 'rejected' ? 'gegen' : 'für' }} das Projekt">
                        <div @class([
                                'h-full rounded-full',
                                'bg-danger' => $leading === 'rejected',
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
                    <div class="p-4 sm:p-6">
                        <div class="text-sm font-semibold text-text-tertiary mb-3">
                            Auszahlung
                        </div>

                        @if($projectProposal->sats_paid > 0)
                            <p class="flex items-center gap-2 text-sm text-success">
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
    <div class="order-2 lg:order-3 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
        <div class="flex items-baseline justify-between gap-2 mb-3">
            <h2 class="text-base font-semibold text-text-primary">
                Vorstandsentscheidung
            </h2>
            <span class="text-sm font-semibold text-text-tertiary">
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
    <div class="order-3 lg:order-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
        <div class="flex items-baseline justify-between gap-2 mb-1">
            <h2 class="text-base font-semibold text-text-primary">
                Stimmungsbild der Mitglieder
            </h2>
            <span class="text-sm font-semibold text-text-tertiary">
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

    {{-- The dialogs sit out of the flow: as items of the side column they
         would add the column gap below the last card. --}}
    <div class="absolute">
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
                        <flux:button wire:click="castVote(true)" variant="primary" icon="hand-thumb-up">
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
                        <flux:button wire:click="castVote(false)" variant="danger" icon="hand-thumb-down">
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
    </div>
</div>
