<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use App\Traits\NostrFetcherTrait;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use NostrFetcherTrait;

    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    #[Locked]
    public ?string $currentPubkey = null;

    #[Locked]
    public bool $currentYearIsPaid = false;

    public ?string $nip05Handle = '';

    #[Locked]
    public bool $nip05Verified = false;

    #[Locked]
    public ?string $nip05VerifiedHandle = null;

    #[Locked]
    public bool $nip05HandleMismatch = false;

    #[Locked]
    public array $nip05VerifiedHandles = [];

    protected $listeners = [
        'nostrLoggedIn' => 'handleNostrLoggedIn',
        'nostrLoggedOut' => 'handleNostrLoggedOut',
    ];

    public function mount(): void
    {
        if (NostrAuth::check()) {
            $this->currentPubkey = NostrAuth::pubkey();
            $this->currentPleb = EinundzwanzigPleb::query()
                ->with([
                    'paymentEvents' => fn ($query) => $query->where('year', date('Y')),
                    'profile',
                ])
                ->where('pubkey', $this->currentPubkey)->first();
            if ($this->currentPleb) {
                if ($this->currentPleb->nip05_handle) {
                    $this->nip05Handle = $this->currentPleb->nip05_handle;

                    // Get all NIP-05 handles for current pubkey
                    $this->nip05VerifiedHandles = $this->getNip05HandlesForPubkey($this->currentPubkey);

                    if (count($this->nip05VerifiedHandles) > 0) {
                        $this->nip05Verified = true;
                        $this->nip05VerifiedHandle = $this->nip05VerifiedHandles[0];

                        // Check if verified handle differs from database handle
                        if (! in_array($this->nip05Handle, $this->nip05VerifiedHandles, true)) {
                            $this->nip05HandleMismatch = true;
                        }
                    }
                }

                // Check if current year is paid
                $paymentEvent = $this->currentPleb->paymentEvents->first();
                if ($paymentEvent && $paymentEvent->paid) {
                    $this->currentYearIsPaid = true;
                }
            }
        }
    }

    public function updatedNip05Handle(): void
    {
        $this->nip05Handle = strtolower($this->nip05Handle);
    }

    public function saveNip05Handle(): void
    {
        $this->validate([
            'nip05Handle' => 'required|string|max:255|regex:/^[a-z0-9_-]+$/|unique:einundzwanzig_plebs,nip05_handle',
        ]);

        $nip05Handle = strtolower($this->nip05Handle);

        $this->currentPleb->update([
            'nip05_handle' => $nip05Handle,
        ]);
        Flux::toast('NIP-05 Handle gespeichert.');
    }

    public function copyRelayUrl(): void
    {
        $relayUrl = 'wss://nostr.einundzwanzig.space';
        $this->js("navigator.clipboard.writeText('{$relayUrl}')");
        Flux::toast('Relay-Adresse in die Zwischenablage kopiert!');
    }

    public function copyWatchtowerUrl(): void
    {
        $watchtowerUrl = '03a09f56bba3d2c200cc55eda2f1f069564a97c1fb74345e1560e2868a8ab3d7d0@62.171.139.240:9911';
        $this->js("navigator.clipboard.writeText('{$watchtowerUrl}')");
        Flux::toast('Watchtower-Adresse in die Zwischenablage kopiert!');
    }

    public function copyBlossomUrl(): void
    {
        $blossomUrl = 'https://blossom.einundzwanzig.space';
        $this->js("navigator.clipboard.writeText('{$blossomUrl}')");
        Flux::toast('Blossom-Adresse in die Zwischenablage kopiert!');
    }

    public function copyGroupRelayUrl(): void
    {
        $groupRelayUrl = 'wss://group.einundzwanzig.space';
        $this->js("navigator.clipboard.writeText('{$groupRelayUrl}')");
        Flux::toast('Community-Relay-Adresse in die Zwischenablage kopiert!');
    }

    public function copyBuzzRelayUrl(): void
    {
        $buzzRelayUrl = 'wss://buzz.einundzwanzig.space';
        $this->js("navigator.clipboard.writeText('{$buzzRelayUrl}')");
        Flux::toast('Buzz-Relay-Adresse in die Zwischenablage kopiert!');
    }

    public function handleNostrLoggedIn($signedEvent = null): void
    {
        NostrAuth::loginWithSignedEvent($signedEvent);
        $this->mount();
    }

    public function handleNostrLoggedOut(): void
    {
        $this->currentPleb = null;
        $this->currentPubkey = null;
        $this->currentYearIsPaid = false;
        $this->nip05Handle = '';
        $this->nip05Verified = false;
        $this->nip05VerifiedHandle = null;
        $this->nip05HandleMismatch = false;
        $this->nip05VerifiedHandles = [];
    }
}
?>

<div>
    @php($isActiveMember = $currentPleb && $currentPleb->association_status->value > 1 && $currentYearIsPaid)

    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl md:text-3xl text-text-primary font-bold">
            Vorteile deiner Mitgliedschaft
        </h1>
        <p class="mt-2 max-w-2xl text-sm text-text-secondary">
            Diese Dienste betreiben wir exklusiv für aktive Vereinsmitglieder. Klicke bei jedem Dienst auf
            <span class="font-medium">„Anleitung anzeigen"</span>, um die Einrichtung Schritt für Schritt zu sehen.
        </p>
    </div>

    <!-- Membership status strip (statt wiederholter Hinweise pro Karte) -->
    @if($isActiveMember)
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            <flux:callout.heading>Mitgliedschaft aktiv</flux:callout.heading>
            <flux:callout.text>Alle Dienste unten sind für dich freigeschaltet.</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout variant="warning" icon="lock-closed" class="mb-6">
            <flux:callout.heading>Dienste gesperrt</flux:callout.heading>
            <flux:callout.text>
                Aktiviere deine Mitgliedschaft, um Relay, NIP-05, Watchtower, den Blossom-Medienserver, die
                Nostr-Community-Gruppe und das Buzz-Relay zu nutzen.
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('association.profile')" size="sm" variant="primary" wire:navigate>
                    Mitgliedschaft aktivieren
                </flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <!-- Benefits Grid - 2 Spalten auf Desktop; Karten wachsen einzeln (md:items-start), damit eine
         aufgeklappte Anleitung die Nachbarkarte nicht mit einem Loch aufbläht -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:items-start">

        <!-- Benefit 1: Nostr Relay -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-bolt text-amber-600 dark:text-amber-400 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Nostr Relay</h3>
                        @if($isActiveMember)
                            <flux:badge color="green" size="sm">Aktiv</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Exklusive Schreib-Rechte auf dem Premium Outbox-Relay von Einundzwanzig.
                    </p>
                </div>
            </div>

            @if($isActiveMember)
                <div class="mt-4 flex items-center gap-2">
                    <button
                        type="button"
                        class="flex-1 text-left text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors break-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        wire:click="copyRelayUrl"
                        title="Klicken zum Kopieren">
                        <code>wss://nostr.einundzwanzig.space</code>
                    </button>
                    <flux:button wire:click="copyRelayUrl" size="sm" variant="ghost" icon="clipboard"
                                 aria-label="Relay-Adresse kopieren"/>
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Anleitung anzeigen">
                        <div class="space-y-2 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
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
        </flux:card>

        <!-- Benefit 2: NIP-05 -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-check-circle text-emerald-600 dark:text-emerald-400 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">NIP-05 Verifizierung</h3>
                        @if($isActiveMember)
                            <flux:badge color="green" size="sm">Aktiv</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Verifiziere deine Identität mit einem menschenlesbaren Nostr-Namen.
                    </p>
                </div>
            </div>

            @if($isActiveMember)
                @php($nip05DisplayHandle = $nip05VerifiedHandle ?: $nip05Handle)
                <div class="mt-4 flex items-center gap-2">
                    <code
                        class="flex-1 text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono break-all">
                        @if($nip05DisplayHandle){{ $nip05DisplayHandle }}@einundzwanzig.space
                        @else Noch kein Handle – unten einrichten
                        @endif
                    </code>
                    @if($nip05Verified)
                        <flux:badge color="green" size="sm">OK</flux:badge>
                    @elseif($nip05Handle)
                        <flux:badge color="amber" size="sm">Wartet</flux:badge>
                    @endif
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="NIP-05 einrichten & verwalten">
                        <div class="space-y-3">
                            <flux:field>
                                <flux:label>Dein NIP-05 Handle</flux:label>
                                <flux:input.group>
                                    <flux:input wire:model.live.debounce="nip05Handle" placeholder="dein-name"/>
                                    <flux:input.group.suffix>@einundzwanzig.space</flux:input.group.suffix>
                                </flux:input.group>
                                <flux:error name="nip05Handle"/>
                            </flux:field>

                            <flux:button wire:click="saveNip05Handle" wire:loading.attr="disabled" size="sm" variant="primary">
                                Speichern
                            </flux:button>

                            @if($nip05Verified)
                                <flux:callout variant="success" icon="check-circle">
                                    <flux:callout.text>
                                        Du hast {{ count($nip05VerifiedHandles) }} aktive Handles für deinen Pubkey.
                                        @if($nip05HandleMismatch)
                                            Die Synchronisation zu
                                            <strong class="break-all">{{ $nip05Handle }}@einundzwanzig.space</strong>
                                            läuft automatisch im Hintergrund.
                                        @endif
                                    </flux:callout.text>
                                </flux:callout>

                                <div class="p-3 bg-white/50 dark:bg-zinc-800/50 rounded border border-zinc-200 dark:border-zinc-600">
                                    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-2">Deine aktivierten Handles:</p>
                                    <ul class="space-y-2">
                                        @foreach($nip05VerifiedHandles as $handle)
                                            <li class="flex items-center gap-2 text-sm" wire:key="handle-{{ $handle }}">
                                                <span class="break-all text-zinc-800 dark:text-zinc-200 font-mono">{{ $handle }}@einundzwanzig.space</span>
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

                            <div class="space-y-2 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
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
        </flux:card>

        <!-- Benefit 3: Lightning Watchtower -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-purple-100 dark:bg-purple-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-shield-halved text-purple-600 dark:text-purple-400 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Lightning Watchtower</h3>
                        @if($isActiveMember)
                            <flux:badge color="green" size="sm">Aktiv</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Schütze deine Lightning Channel – auch wenn deine Node offline ist.
                    </p>
                </div>
            </div>

            @if($isActiveMember)
                <div class="mt-4 flex items-center gap-2">
                    <button
                        type="button"
                        class="flex-1 text-left text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors break-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        wire:click="copyWatchtowerUrl"
                        title="Klicken zum Kopieren">
                        <code>03a09f56bba3d2c200cc55eda2f1f069564a97c1fb74345e1560e2868a8ab3d7d0@62.171.139.240:9911</code>
                    </button>
                    <flux:button wire:click="copyWatchtowerUrl" size="sm" variant="ghost" icon="clipboard"
                                 aria-label="Watchtower-Adresse kopieren"/>
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Anleitung anzeigen">
                        <div class="space-y-2 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            <p>
                                Ein Watchtower überwacht deine Channel und springt ein, falls deine Node offline ist –
                                so verhinderst du den Verlust deiner Sats bei unfairen Channel-Schließungen.
                            </p>
                            <p>Füge die URI oben in deiner Lightning-Node-Konfiguration hinzu:</p>
                            <ul class="space-y-1 list-disc list-inside">
                                <li><strong>LND:</strong> <flux:link href="https://docs.lightning.engineering/lightning-network-tools/lnd/watchtower" target="_blank">Doku</flux:link></li>
                                <li><strong>Core Lightning:</strong> <code class="bg-zinc-200 dark:bg-zinc-700 px-1 rounded">watchtower-client</code>-Plugin mit der URI</li>
                                <li><strong>Eclair:</strong> URI in der <code class="bg-zinc-200 dark:bg-zinc-700 px-1 rounded">eclair.conf</code> ergänzen</li>
                            </ul>
                            <p>
                                <strong>Wichtig:</strong> Der Watchtower überwacht passiv. Er hat keinen Zugriff auf
                                deine privaten Schlüssel oder dein Guthaben.
                            </p>
                        </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </flux:card>

        <!-- Benefit 4: Blossom Medienserver (NEU) -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-rose-100 dark:bg-rose-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-cloud-arrow-up text-rose-600 dark:text-rose-400 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Blossom Medienserver</h3>
                        <div class="flex items-center gap-1.5">
                            <flux:badge color="rose" size="sm">NEU</flux:badge>
                            @if($isActiveMember)
                                <flux:badge color="green" size="sm">Aktiv</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                            @endif
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Dein eigener Speicher für Bilder &amp; Videos auf Nostr – betrieben vom Verein.
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        <flux:badge color="rose" size="sm" icon="circle-stack">5 GB Speicher</flux:badge>
                        <flux:badge color="zinc" size="sm" icon="arrow-up-tray">max. 1 GB pro Datei</flux:badge>
                    </div>
                </div>
            </div>

            @if($isActiveMember)
                <div class="mt-4 flex items-center gap-2">
                    <button
                        type="button"
                        class="flex-1 text-left text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors break-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        wire:click="copyBlossomUrl"
                        title="Klicken zum Kopieren">
                        <code>https://blossom.einundzwanzig.space</code>
                    </button>
                    <flux:button wire:click="copyBlossomUrl" size="sm" variant="ghost" icon="clipboard"
                                 aria-label="Blossom-Adresse kopieren"/>
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Was ist Blossom & wie nutze ich ihn?">
                        <div class="space-y-3 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            <p>
                                Wenn du auf Nostr ein Bild oder Video postest, muss diese Datei irgendwo gespeichert
                                werden. Bisher landet sie oft auf fremden Gratis-Servern, die jederzeit verschwinden
                                können. Mit unserem <strong>Blossom-Server</strong> liegen deine Medien stattdessen
                                sicher auf einem Server des Vereins – schnell, zuverlässig und nur für Mitglieder.
                            </p>
                            <div>
                                <p class="font-medium text-zinc-700 dark:text-zinc-300 mb-1">So nutzt du ihn:</p>
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
        </flux:card>

        <!-- Benefit 5: Nostr Community / NIP-29 Gruppe (NEU) -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-sky-100 dark:bg-sky-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-comments text-sky-600 dark:text-sky-400 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Nostr Community-Gruppe</h3>
                        <div class="flex items-center gap-1.5">
                            <flux:badge color="sky" size="sm">NEU</flux:badge>
                            @if($isActiveMember)
                                <flux:badge color="green" size="sm">Aktiv</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                            @endif
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Chatte und poste in der Einundzwanzig-Gruppe auf unserem Community-Relay
                        (<flux:link href="https://github.com/nostr-protocol/nips/blob/master/29.md" target="_blank">NIP-29</flux:link>)
                        – wie Discord, nur auf Nostr.
                    </p>
                </div>
            </div>

            @if($isActiveMember)
                <div class="mt-4 flex flex-col gap-2">
                    <flux:button
                        href="https://group.einundzwanzig.space"
                        target="_blank"
                        variant="primary"
                        size="sm"
                        icon:trailing="arrow-up-right"
                        class="w-full">
                        Gruppen Web App öffnen
                    </flux:button>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="flex-1 text-left text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors break-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            wire:click="copyGroupRelayUrl"
                            title="Klicken zum Kopieren">
                            <code>wss://group.einundzwanzig.space</code>
                        </button>
                        <flux:button wire:click="copyGroupRelayUrl" size="sm" variant="ghost" icon="clipboard"
                                     aria-label="Community-Relay-Adresse kopieren"/>
                    </div>
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Was ist das & wie trete ich bei?">
                        <div class="space-y-3 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            <p>
                                Unser <strong>group.einundzwanzig.space</strong> ist ein Community-Relay nach dem
                                <flux:link href="https://github.com/nostr-protocol/nips/blob/master/29.md" target="_blank">NIP-29</flux:link>-Standard
                                (relay-basierte Gruppen): ein Relay bildet eine Community mit Chat, Threads und Events –
                                nur für Vereinsmitglieder.
                            </p>
                            <div>
                                <p class="font-medium text-zinc-700 dark:text-zinc-300 mb-1">So trittst du bei:</p>
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
        </flux:card>

        <!-- Benefit 6: Buzz Relay (NEU, experimentell) -->
        <flux:card
            class="flex flex-col {{ $isActiveMember ? '' : 'opacity-60' }}">
            <div class="flex items-start gap-3">
                <div
                    class="shrink-0 w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/60 flex items-center justify-center">
                    <i class="fa-sharp-duotone fa-solid fa-robot text-indigo-600 dark:text-indigo-300 text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">Buzz Relay</h3>
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <flux:badge color="indigo" size="sm">NEU</flux:badge>
                            <flux:badge color="yellow" size="sm" icon="beaker">Experimentell</flux:badge>
                            @if($isActiveMember)
                                <flux:badge color="green" size="sm">Aktiv</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" icon="lock-closed">Mitglieder</flux:badge>
                            @endif
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Arbeitsraum für Menschen und KI-Agenten – Kanäle, Threads, Dateien und Code, komplett auf
                        Nostr.
                    </p>
                </div>
            </div>

            @if($isActiveMember)
                <flux:callout variant="warning" icon="beaker" class="mt-4">
                    <flux:callout.text>
                        <strong>Testbetrieb.</strong> Buzz ist junge Software mit hohem Änderungstempo – rechne mit
                        Fehlern und Brüchen. Nutze das Relay nicht als einzigen Ort für wichtige Daten.
                    </flux:callout.text>
                </flux:callout>

                <div class="mt-3 flex flex-col gap-2">
                    <flux:button
                        href="https://github.com/block/buzz/releases/latest"
                        target="_blank"
                        variant="primary"
                        size="sm"
                        icon:trailing="arrow-up-right"
                        class="w-full">
                        Buzz Desktop App laden
                    </flux:button>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="flex-1 text-left text-xs bg-zinc-100 dark:bg-zinc-800 px-3 py-2 rounded text-zinc-700 dark:text-zinc-300 font-mono cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors break-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            wire:click="copyBuzzRelayUrl"
                            title="Klicken zum Kopieren">
                            <code>wss://buzz.einundzwanzig.space</code>
                        </button>
                        <flux:button wire:click="copyBuzzRelayUrl" size="sm" variant="ghost" icon="clipboard"
                                     aria-label="Buzz-Relay-Adresse kopieren"/>
                    </div>
                </div>

                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Was ist Buzz & wie starte ich?">
                        <div class="space-y-3 max-w-[68ch] text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
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
                                <p class="font-medium text-zinc-700 dark:text-zinc-300 mb-1">Was unser Relay kann:</p>
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
                                <p class="mt-1">Noch nicht fertig: Sprach-Huddles und die Mobile-Apps.</p>
                            </div>

                            <div>
                                <p class="font-medium text-zinc-700 dark:text-zinc-300 mb-1">So startest du:</p>
                                <ol class="space-y-1 list-decimal list-inside">
                                    <li>Lade die <strong>Buzz Desktop App</strong> über den Button oben
                                        (<flux:link href="https://github.com/block/buzz/releases/latest" target="_blank">GitHub Releases</flux:link>)
                                        – es gibt Builds für macOS, Windows und Linux.</li>
                                    <li>Trage beim ersten Start unser Relay <strong>wss://buzz.einundzwanzig.space</strong>
                                        als Server ein.</li>
                                    <li>Melde dich mit deinem Nostr-Schlüssel an – fertig.</li>
                                </ol>
                                <p class="mt-1">
                                    <strong>Windows-Hinweis:</strong> Der Windows-Installer ist derzeit
                                    <em>nicht signiert</em> (Dateiname enthält <code class="bg-zinc-200 dark:bg-zinc-700 px-1 rounded">alpha-unsigned</code>).
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
        </flux:card>

    </div>
</div>
