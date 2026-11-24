<?php

use App\Livewire\Traits\WithViewer;
use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\Nip05Directory;
use App\Services\Nostr\Nip05Status;
use App\Support\NostrAuth;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use WithViewer;

    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    #[Locked]
    public ?string $currentPubkey = null;

    #[Locked]
    public bool $currentYearIsPaid = false;

    public ?string $nip05Handle = '';

    #[Locked]
    public Nip05Status $nip05;

    protected $listeners = [
        'nostrLoggedOut' => 'handleNostrLoggedOut',
    ];

    public function mount(Nip05Directory $nip05Directory): void
    {
        $this->nip05 = Nip05Status::none();

        if (NostrAuth::check()) {
            $this->currentPubkey = NostrAuth::pubkey();
            $this->currentPleb = EinundzwanzigPleb::query()
                ->with('profile')
                ->where('pubkey', $this->currentPubkey)->first();
            if ($this->currentPleb) {
                if ($this->currentPleb->nip05_handle) {
                    $this->nip05Handle = $this->currentPleb->nip05_handle;
                    $this->nip05 = $nip05Directory->statusFor($this->currentPubkey, $this->nip05Handle);
                }

                // Every paying member: Passiv, Aktiv or Ehrenmitglied with this year's fee paid.
                $this->currentYearIsPaid = $this->currentPleb->hasPaidMembership();
            }
        }
    }

    public function updatedNip05Handle(): void
    {
        $this->nip05Handle = strtolower($this->nip05Handle);
    }

    public function saveNip05Handle(Nip05Directory $nip05Directory): void
    {
        /*
         * Regeln aus dem Model, damit dieser Weg, ProfileForm und die API
         * nicht auseinanderlaufen — Begruendung der einzelnen Teile in
         * `EinundzwanzigPleb::NIP05_HANDLE_RULES`. Das Literal hier fehlte
         * `/D` (ein abschliessender Zeilenumbruch kam durch die Regel und
         * wurde nur von der Middleware entfernt) und liess den reservierten
         * Namen `_` zu, mit dem man als der Verein selbst auftritt.
         */
        $this->validate([
            'nip05Handle' => 'required|'.EinundzwanzigPleb::NIP05_HANDLE_RULES.'|unique:einundzwanzig_plebs,nip05_handle',
        ]);

        $nip05Handle = strtolower($this->nip05Handle);

        $this->actingPleb()->update([
            'nip05_handle' => $nip05Handle,
        ]);
        $this->nip05Handle = $nip05Handle;
        $this->nip05 = $nip05Directory->statusFor($this->currentPubkey, $nip05Handle, fresh: true);
        Flux::toast('NIP-05 Handle gespeichert.');
    }

    public function handleNostrLoggedOut(): void
    {
        $this->currentPleb = null;
        $this->currentPubkey = null;
        $this->currentYearIsPaid = false;
        $this->nip05Handle = '';
        $this->nip05 = Nip05Status::none();
    }
}
?>

<div class="mx-auto w-full max-w-3xl pb-32">
    @php($isPayingMember = $currentPleb && $currentYearIsPaid)

    <x-page-header title="Vorteile deiner Mitgliedschaft">
        <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
            Diese Dienste betreiben wir exklusiv für zahlende Vereinsmitglieder. Klicke bei jedem Dienst auf
            <span class="font-semibold text-text-primary">„Anleitung anzeigen"</span>, um die Einrichtung Schritt für Schritt zu sehen.
        </p>
    </x-page-header>

    {{-- One status line for the whole page instead of a notice per service. --}}
    @if($isPayingMember)
        <flux:callout variant="success" icon="check-circle" class="mb-8">
            <flux:callout.heading>Mitgliedschaft aktiv</flux:callout.heading>
            <flux:callout.text>Alle Dienste unten sind für dich freigeschaltet.</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout variant="warning" icon="lock-closed" class="mb-8">
            <flux:callout.heading>Dienste gesperrt</flux:callout.heading>
            <flux:callout.text>
                Aktiviere deine Mitgliedschaft, um Relay, NIP-05, Watchtower, den Blossom-Medienserver, die
                Nostr-Community-Gruppe und das Buzz-Relay zu nutzen.
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('association.profile')" variant="primary" class="min-h-11" wire:navigate>
                    Mitgliedschaft aktivieren
                </flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <ul class="divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface" aria-label="Dienste für Mitglieder">
        <x-benefit-item icon="bolt" title="Nostr Relay" :active="$isPayingMember"
                        description="Exklusive Schreib-Rechte auf dem Premium Outbox-Relay von Einundzwanzig.">
            @if($isPayingMember)
                <x-copy-field value="wss://nostr.einundzwanzig.space" label="Relay-Adresse" />
                <flux:accordion>
                    <flux:accordion.item heading="Anleitung anzeigen">
                <div class="space-y-2 max-w-prose text-base leading-normal text-text-secondary">
                    <p>
                        Ein Outbox-Relay ist wie ein Postbote für deine Nostr-Nachrichten: Es speichert
                        und verteilt deine Posts.
                    </p>
                    <p>
                        Gehe in deinem Nostr-Client zu den Einstellungen (meist „Settings" oder „Relays")
                        und füge die Adresse oben als Outbox-Relay hinzu.
                    </p>
                    <p>
                        <strong>Tipp:</strong> Du kannst auf mehreren Relays gleichzeitig veröffentlichen –
                        so sind deine Inhalte auch über unser Relay erreichbar.
                    </p>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>

        <x-benefit-item icon="check-badge" title="NIP-05 Verifizierung" :active="$isPayingMember"
                        description="Verifiziere deine Identität mit einem menschenlesbaren Nostr-Namen.">
            @if($isPayingMember)
                @php($nip05DisplayHandle = $nip05->verifiedHandle ?: $nip05Handle)
                <p class="flex flex-wrap items-center gap-2 text-base text-text-primary">
                    <span class="break-all">
                        @if($nip05DisplayHandle){{ $nip05DisplayHandle }}@einundzwanzig.space
                        @else Noch kein Handle – unten einrichten
                        @endif
                    </span>
                    @if($nip05->verified)
                        <flux:badge color="green" size="sm" icon="check">OK</flux:badge>
                    @elseif($nip05Handle)
                        <flux:badge color="amber" size="sm" icon="clock">Wartet</flux:badge>
                    @endif
                </p>
                <flux:accordion>
                    <flux:accordion.item heading="NIP-05 einrichten & verwalten">
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Dein NIP-05 Handle</flux:label>
                        <flux:input.group>
                            <flux:input wire:model.live.debounce="nip05Handle" placeholder="dein-name"/>
                            <flux:input.group.suffix>@einundzwanzig.space</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="nip05Handle"/>
                    </flux:field>

                    <flux:button wire:click="saveNip05Handle" wire:loading.attr="disabled" variant="primary" class="min-h-11">
                        Speichern
                    </flux:button>

                    @if($nip05->verified)
                        <flux:callout variant="success" icon="check-circle">
                            <flux:callout.text>
                                Du hast {{ count($nip05->handles) }} aktive Handles für deinen Pubkey.
                                @if($nip05->mismatch)
                                    Die Synchronisation zu
                                    <strong class="break-all">{{ $nip05Handle }}@einundzwanzig.space</strong>
                                    läuft automatisch im Hintergrund.
                                @endif
                            </flux:callout.text>
                        </flux:callout>

                        <div class="rounded-lg border border-border-default p-4">
                            <p class="mb-2 text-sm font-semibold text-text-secondary">Deine aktivierten Handles:</p>
                            <ul class="space-y-2">
                                @foreach($nip05->handles as $handle)
                                    <li class="flex items-center gap-2 text-base" wire:key="handle-{{ $handle }}">
                                        <span class="break-all text-text-primary">{{ $handle }}@einundzwanzig.space</span>
                                        <flux:badge color="green" size="sm">OK</flux:badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif($nip05Handle)
                        <flux:callout variant="secondary" icon="information-circle">
                            <flux:callout.text>
                                Dein Handle <strong class="break-all">{{ $nip05Handle }}@einundzwanzig.space</strong>
                                ist gespeichert, aber noch nicht aktiv. Der Vorstand schaltet es bald frei.
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="space-y-2 max-w-prose text-base leading-normal text-text-secondary">
                        <p>
                            <flux:link href="https://nostr.how/en/guides/get-verified#self-hosted" target="_blank">NIP-05</flux:link>
                            funktioniert wie eine E-Mail-Adresse (z.B. name@einundzwanzig.space) und zeigt
                            in Clients ein Häkchen – das macht dein Profil vertrauenswürdiger und leichter teilbar.
                        </p>
                        <p>
                            <strong>Regeln für dein Handle:</strong> Nur Kleinbuchstaben (a–z), Zahlen (0–9)
                            sowie „-" und „_". Großbuchstaben werden automatisch kleingeschrieben.
                        </p>
                    </div>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>

        <x-benefit-item icon="shield-check" title="Lightning Watchtower" :active="$isPayingMember"
                        description="Schütze deine Lightning Channel – auch wenn deine Node offline ist.">
            @if($isPayingMember)
                <x-copy-field value="02ce7b8a6bc3fb6245ecdcf67b25d80d03e1fea02459ae9af9e64ac1590139e467@62.171.139.240:9911" label="Watchtower-Adresse" />
                <flux:accordion>
                    <flux:accordion.item heading="Anleitung anzeigen">
                <div class="space-y-2 max-w-prose text-base leading-normal text-text-secondary">
                    <p>
                        Ein Watchtower überwacht deine Channel und springt ein, falls deine Node offline ist –
                        so verhinderst du den Verlust deiner Sats bei unfairen Channel-Schließungen.
                    </p>
                    <p>Füge die URI oben in deiner Lightning-Node-Konfiguration hinzu:</p>
                    <ul class="space-y-1 list-disc list-inside">
                        <li><strong>LND:</strong> <flux:link href="https://docs.lightning.engineering/lightning-network-tools/lnd/watchtower" target="_blank">Doku</flux:link></li>
                        <li><strong>Core Lightning:</strong> <code class="rounded bg-bg-elevated px-1">watchtower-client</code>-Plugin mit der URI</li>
                        <li><strong>Eclair:</strong> URI in der <code class="rounded bg-bg-elevated px-1">eclair.conf</code> ergänzen</li>
                    </ul>
                    <p>
                        <strong>Wichtig:</strong> Der Watchtower überwacht passiv. Er hat keinen Zugriff auf
                        deine privaten Schlüssel oder dein Guthaben.
                    </p>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>

        <x-benefit-item icon="cloud-arrow-up" title="Blossom Medienserver" :active="$isPayingMember"
                        description="Dein eigener Speicher für Bilder & Videos auf Nostr – betrieben vom Verein.">
            <x-slot:badges><flux:badge size="sm">Neu</flux:badge></x-slot:badges>
            <p class="text-base text-text-tertiary">5 GB Speicher · max. 1 GB pro Datei</p>
            @if($isPayingMember)
                <x-copy-field value="https://blossom.einundzwanzig.space" label="Blossom-Adresse" />
                <flux:accordion>
                    <flux:accordion.item heading="Was ist Blossom & wie nutze ich ihn?">
                <div class="space-y-3 max-w-prose text-base leading-normal text-text-secondary">
                    <p>
                        Wenn du auf Nostr ein Bild oder Video postest, muss diese Datei irgendwo gespeichert
                        werden. Bisher landet sie oft auf fremden Gratis-Servern, die jederzeit verschwinden
                        können. Mit unserem <strong>Blossom-Server</strong> liegen deine Medien stattdessen
                        sicher auf einem Server des Vereins – schnell, zuverlässig und nur für Mitglieder.
                    </p>
                    <div>
                        <p class="mb-1 font-semibold text-text-primary">So nutzt du ihn:</p>
                        <ol class="space-y-1 list-decimal list-inside">
                            <li>Öffne deinen Nostr-Client (z.B. Amethyst, Primal, nostrudel, Nostur).</li>
                            <li>Gehe zu den Einstellungen → <strong>„Medienserver"</strong> (manchmal „Media
                                Servers", „File Storage" oder „Blossom").</li>
                            <li>Füge die Adresse oben hinzu und setze sie als Standard.</li>
                            <li>Fertig! Deine hochgeladenen Bilder &amp; Videos landen ab jetzt auf dem
                                Vereinsserver.</li>
                        </ol>
                    </div>
                    <p>
                        <strong>Dein Kontingent:</strong> 5 GB Speicherplatz pro Mitglied, einzelne Dateien
                        bis maximal 1 GB. Deine hochgeladenen Medien kannst du jederzeit auf
                        <flux:link href="https://media.einundzwanzig.space" target="_blank">media.einundzwanzig.space</flux:link>
                        ansehen und verwalten.
                    </p>
                    <p>
                        <strong>Sicher:</strong> Die Anmeldung passiert automatisch über deinen Nostr-Schlüssel –
                        nur Vereinsmitglieder können hochladen, und deine privaten Schlüssel verlassen
                        niemals dein Gerät.
                    </p>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>

        <x-benefit-item icon="chat-bubble-left-right" title="Nostr Community-Gruppe" :active="$isPayingMember">
            <x-slot:badges><flux:badge size="sm">Neu</flux:badge></x-slot:badges>
            <x-slot:description>
                Chatte und poste in der Einundzwanzig-Gruppe auf unserem Community-Relay
                (<flux:link href="https://github.com/nostr-protocol/nips/blob/master/29.md" target="_blank">NIP-29</flux:link>)
                – wie Discord, nur auf Nostr.
            </x-slot:description>
            @if($isPayingMember)
                <div>
                    <flux:button href="https://group.einundzwanzig.space" target="_blank" variant="primary" icon:trailing="arrow-up-right" class="min-h-11">
                        Gruppen Web App öffnen
                    </flux:button>
                </div>
                <x-copy-field value="wss://group.einundzwanzig.space" label="Community-Relay-Adresse" />
                <flux:accordion>
                    <flux:accordion.item heading="Was ist das & wie trete ich bei?">
                <div class="space-y-3 max-w-prose text-base leading-normal text-text-secondary">
                    <p>
                        Unser <strong>group.einundzwanzig.space</strong> ist ein Community-Relay nach dem
                        <flux:link href="https://github.com/nostr-protocol/nips/blob/master/29.md" target="_blank">NIP-29</flux:link>-Standard
                        (relay-basierte Gruppen): ein Relay bildet eine Community mit Chat, Threads und Events –
                        nur für Vereinsmitglieder.
                    </p>
                    <div>
                        <p class="mb-1 font-semibold text-text-primary">So trittst du bei:</p>
                        <ol class="space-y-1 list-decimal list-inside">
                            <li>Öffne die Gruppe mit einem NIP-29-fähigen Nostr-Client. Am einfachsten über den
                                Button oben (öffnet unsere <flux:link href="https://group.einundzwanzig.space" target="_blank">Gruppen Web App</flux:link>);
                                es funktionieren aber auch andere Clients wie
                                <flux:link href="https://www.chachi.chat" target="_blank">Chachi</flux:link>.</li>
                            <li>Alternativ das Relay <strong>wss://group.einundzwanzig.space</strong> in deinem
                                Client als Gruppe hinzufügen.</li>
                            <li>Melde dich mit deinem Nostr-Schlüssel an (z.B. via Amber oder nsec-Bunker) –
                                fertig, du kannst sofort lesen und schreiben.</li>
                        </ol>
                    </div>
                    <p>
                        <strong>Automatischer Zugang:</strong> Sobald dein Mitgliedsbeitrag bezahlt ist, wirst du
                        automatisch als Member des Relays eingetragen und erhältst Schreib-Rechte in der Gruppe.
                    </p>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>

        <x-benefit-item icon="cpu-chip" title="Buzz Relay" :active="$isPayingMember"
                        description="Arbeitsraum für Menschen und KI-Agenten – Kanäle, Threads, Dateien und Code, komplett auf Nostr.">
            <x-slot:badges>
                <flux:badge size="sm">Neu</flux:badge>
                <flux:badge color="amber" size="sm" icon="beaker">Experimentell</flux:badge>
            </x-slot:badges>
            @if($isPayingMember)
                <flux:callout variant="warning" icon="beaker">
                    <flux:callout.text>
                        <strong>Testbetrieb.</strong> Buzz ist junge Software mit hohem Änderungstempo – rechne mit
                        Fehlern und Brüchen. Nutze das Relay nicht als einzigen Ort für wichtige Daten.
                    </flux:callout.text>
                </flux:callout>
                <div>
                    <flux:button href="https://github.com/block/buzz/releases/latest" target="_blank" variant="primary" icon:trailing="arrow-up-right" class="min-h-11">
                        Buzz Desktop App laden
                    </flux:button>
                </div>
                <x-copy-field value="wss://buzz.einundzwanzig.space" label="Buzz-Relay-Adresse" />
                <flux:accordion>
                    <flux:accordion.item heading="Was ist Buzz & wie starte ich?">
                <div class="space-y-3 max-w-prose text-base leading-normal text-text-secondary">
                    <p>
                        <flux:link href="https://github.com/block/buzz" target="_blank">Buzz</flux:link>
                        ist eine quelloffene Arbeitsplattform von Block (Apache-2.0) – ungefähr „Slack plus
                        GitHub", nur dass alles auf <strong>Nostr</strong> läuft: jede Nachricht, jede
                        Reaktion und jede Mitgliedschaft ist ein signiertes Nostr-Event. Die Besonderheit:
                        KI-Agenten sind dort vollwertige Mitglieder mit eigenem Schlüssel statt mit einem
                        Bot-Token. Herzstück ist immer ein eigenes Relay – und genau das betreiben wir jetzt
                        für den Verein.
                    </p>

                    <div>
                        <p class="mb-1 font-semibold text-text-primary">Was unser Relay kann:</p>
                        <ul class="space-y-1 list-disc list-inside">
                            <li><strong>Kanäle &amp; Threads</strong> – Gruppen nach
                                <flux:link href="https://github.com/nostr-protocol/nips/blob/master/29.md" target="_blank">NIP-29</flux:link>,
                                offen oder privat, mit Rollen und Moderation.</li>
                            <li><strong>Private Nachrichten</strong> – verschlüsselt nach NIP-17.</li>
                            <li><strong>Dateien &amp; Volltextsuche</strong> – Upload ins Relay (Blossom),
                                Suche über alle Gespräche (NIP-50).</li>
                            <li><strong>Code &amp; Automatisierung</strong> – Repos und Patches als
                                Nostr-Events (NIP-34), Workflows per Nachricht, Zeitplan oder Webhook.</li>
                        </ul>
                        <p class="mt-2">Noch nicht fertig: Sprach-Huddles und die Mobile-Apps.</p>
                    </div>

                    <div>
                        <p class="mb-1 font-semibold text-text-primary">So startest du:</p>
                        <ol class="space-y-1 list-decimal list-inside">
                            <li>Lade die <strong>Buzz Desktop App</strong> über den Button oben
                                (<flux:link href="https://github.com/block/buzz/releases/latest" target="_blank">GitHub Releases</flux:link>)
                                – es gibt Builds für macOS, Windows und Linux.</li>
                            <li>Trage beim ersten Start unser Relay <strong>wss://buzz.einundzwanzig.space</strong>
                                als Server ein.</li>
                            <li>Melde dich mit deinem Nostr-Schlüssel an – fertig.</li>
                        </ol>
                        <p class="mt-2">
                            <strong>Windows-Hinweis:</strong> Der Windows-Installer ist derzeit
                            <em>nicht signiert</em> (Dateiname enthält <code class="rounded bg-bg-elevated px-1">alpha-unsigned</code>).
                            SmartScreen wird warnen – das ist beim aktuellen Stand erwartbar.
                        </p>
                    </div>

                    <p>
                        <strong>Vorerst nur Desktop:</strong> Die Buzz-Unterstützung unserer
                        <flux:link href="https://group.einundzwanzig.space" target="_blank">Gruppen Web App</flux:link>
                        (group.einundzwanzig.space) ist in Arbeit – bis dahin ist die Desktop App der Weg.
                        Fremde Nostr-Clients zeigen Buzz-Inhalte nicht vollständig an, weil Buzz eigene
                        Event-Typen für Rich Content und Bearbeitungen nutzt.
                    </p>

                    <p>
                        <strong>Und die Community-Gruppe?</strong> Die bleibt der Ort für den alltäglichen
                        Austausch im Browser, ohne Installation. Buzz ist der Arbeitsraum für Projekte, Code
                        und Agenten – beide laufen auf eigenen Relays.
                    </p>

                    <p>
                        <strong>Automatischer Zugang:</strong> Sobald dein Mitgliedsbeitrag bezahlt ist, wirst
                        du in der Nacht darauf automatisch als Member des Buzz-Relays eingetragen und
                        erhältst Schreib-Rechte. Das Relay ist geschlossen: Anmeldung per Schlüssel ist
                        Pflicht, schreiben dürfen nur freigegebene Mitglieder. Kosten entstehen dir keine.
                    </p>
                </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </x-benefit-item>
    </ul>
</div>
