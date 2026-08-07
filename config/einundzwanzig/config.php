<?php

return [
    /*
     * Jahresbeitrag und Waehrung — die EINZIGE Quelle fuer beides.
     *
     * Vorher stand der Betrag zweimal als
     * `config('app.env') === 'production' ? 21000 : 1` im Volt-Component. Das
     * band den Preis an den Umgebungsnamen statt an eine Einstellung: unter
     * APP_ENV=staging kostete die Mitgliedschaft 1 Satoshi.
     *
     * Die Waehrung ging bisher gar nicht an BTCPay — der Betrag wurde nackt
     * gesendet und BTCPay setzte die Store-Default-Waehrung ein. Aendert die
     * sich dort, aendert sich der Beitrag stillschweigend um Groessenordnungen.
     * "SATS" ist der BTCPay-Code fuer Satoshis und passt zu Betrag (21000) und
     * Beschriftung ("Pay 21000 Sats") der bisherigen Oberflaeche.
     */
    'membership_fee' => (int) env('MEMBERSHIP_FEE', 21000),
    'currency' => (string) env('MEMBERSHIP_CURRENCY', 'SATS'),

    /*
     * Die Statuten, auf die sich ein Antrag bezieht — Adresse UND Fassung.
     *
     * Konfigurierbar und nicht einbetoniert, weil sich beides bei der
     * naechsten Mitgliederversammlung aendert: Die Fassung v1.3 wurde am
     * 20.10.2024 angenommen, eine v1.4 braucht dann eine neue Datei und eine
     * neue Nummer. Stuende die URL im Code, waere eine Satzungsaenderung ein
     * Deploy mit Codeaenderung — und bis dahin verwiese `GET /membership/config`
     * jeden Antragsteller auf eine Fassung, die nicht mehr gilt.
     *
     * `version` ist bewusst ein eigener Wert und nicht aus dem Dateinamen
     * geraten: ein Client, der die Zustimmung protokolliert, muss festhalten
     * koennen, WELCHER Fassung zugestimmt wurde.
     */
    'statutes' => [
        'url' => (string) env(
            'MEMBERSHIP_STATUTES_URL',
            'https://einundzwanzig.space/files/Statuten_v1.3.pdf'
        ),
        'version' => (string) env('MEMBERSHIP_STATUTES_VERSION', '1.3'),
        'adopted_at' => (string) env('MEMBERSHIP_STATUTES_ADOPTED_AT', '2024-10-20'),
    ],

    /*
     * Server-zu-Server-Schluessel der /api/v1-Clients, als Abbildung
     * Name -> Key. Format der Env-Variable:
     *
     *   API_CLIENT_KEYS="einundzwanzig-group:<key>,weiterer-client:<key>"
     *
     * Der NAME ist der Zweck dieser Struktur: er wandert in Log und
     * Rate-Limiter-Schluessel, der Key nie. Eine nackte Key-Liste haette
     * keine Zurechnung — man wuesste dann, dass jemand berechtigt war, aber
     * nicht wer.
     *
     * Fail-closed: nicht gesetzt, leer oder unparsbar ergibt eine LEERE
     * Abbildung, und eine leere Abbildung passt auf keinen Key. "Nichts
     * konfiguriert" darf nie "jeder darf" heissen.
     *
     * Rotation und Sperre kosten hier eine Env-Aenderung plus config:cache,
     * also einen Deploy-Schritt. Bei wenigen bekannten Abnehmern ist das der
     * richtige Zuschnitt; ab mehreren Fremd-Clients oder bei Bedarf an
     * sofortiger Sperre ist VerifyApiClient die einzige Stelle, die auf eine
     * Tabelle umgestellt werden muss.
     *
     * Einen Key erzeugt `php artisan api:client-key <name>` — das Kommando
     * gibt die fertige Env-Zeile aus und schreibt selbst nichts.
     */
    'api_client_keys' => (static function (): array {
        $map = [];

        foreach (explode(',', (string) env('API_CLIENT_KEYS', '')) as $pair) {
            if (! str_contains($pair, ':')) {
                continue;
            }

            [$name, $key] = explode(':', $pair, 2);
            $name = trim($name);
            $key = trim($key);

            /*
             * Der Key muss aussehen wie einer aus `api:client-key`:
             * mindestens 32 Zeichen, nur [A-Za-z0-9_-]. Das ist der billige
             * Riegel gegen den stillen Fehler dieser Grammatik — steht ein
             * Komma IM Key ("a:ke,y1"), schneidet der Split ihn ab, und der
             * gekuerzte Rest waere sonst ein gueltiger, kuerzerer Key
             * gewesen. Lieber gar kein Eintrag als ein heimlich anderer.
             */
            if ($name === '' || preg_match('/^[A-Za-z0-9_-]{32,}$/', $key) !== 1) {
                continue;
            }

            // Erster Eintrag gewinnt: ein doppelt vergebener Name soll den
            // bereits gueltigen Key nicht ueberschreiben.
            if (! array_key_exists($name, $map)) {
                $map[$name] = $key;
            }
        }

        return $map;
    })(),

    /*
     * Kontingente der /api/v1-Fläche. Konfigurierbar, weil sie in zwei
     * Dimensionen zaehlen, die sich unabhaengig voneinander verschieben:
     * pro Client (was eine Anwendung insgesamt darf) und pro Pubkey (was
     * ein einzelner Endnutzer durch sie hindurch darf).
     *
     * Das Invoice-Kontingent ist absichtlich eng: jeder Aufruf ist ein POST
     * an BTCPay mit dem API-Key des VEREINS, also fremde Arbeit auf unsere
     * Rechnung. Drei pro Pubkey und Tag decken Erst-, Wiederhol- und
     * Korrekturversuch ab; alles darueber ist kein Beitritt mehr.
     */
    'api_rate_limits' => [
        'client_per_minute' => (int) env('API_RATE_LIMIT_CLIENT_PER_MINUTE', 120),
        'pubkey_per_minute' => (int) env('API_RATE_LIMIT_PUBKEY_PER_MINUTE', 30),
        'invoice_per_day' => (int) env('API_RATE_LIMIT_INVOICE_PER_DAY', 3),
    ],

    /*
     * Cache-Store fuer die NIP-98-Replay-Sperre. null = Standard-Store.
     *
     * Eigener Schalter, weil diese Sperre die einzige Stelle ist, an der ein
     * Cache-Ausfall sicherheitsrelevant wird: faellt der Store aus, gibt es
     * keine Replay-Abwehr mehr, und Nip98::consume() macht dann zu (503)
     * statt auf. Der Schalter macht genau diesen Fehlerpfad pruefbar, ohne
     * den Store der uebrigen Anwendung anzufassen.
     *
     * `?: null` ist NICHT kosmetisch. Dotenv liefert fuer die Zeile
     * `API_REPLAY_CACHE_STORE=` den LEEREN STRING, und
     * CacheManager::store() faellt nur bei null auf den Standard-Store
     * zurueck — bei '' wirft es `Cache store [] is not defined`. Ohne diese
     * Zeile legte die ausgelieferte Beispielkonfiguration die gesamte API
     * stumm auf 503 (am laufenden Server nachgemessen), und im Testlauf war
     * es unsichtbar, weil phpunit.xml die Variable gar nicht setzt und
     * env() dann null liefert.
     *
     * Der Store muss GETEILT und atomar sein: `database` oder `redis`.
     * `array` ist prozesslokal (jeder PHP-FPM-Worker haette seine eigene
     * Replay-Liste), `file` hat kein atomares add().
     */
    'api_replay_cache_store' => env('API_REPLAY_CACHE_STORE') ?: null,

    'current_board' => [
        'npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6',
        'npub1gvqkjccl9urg93svaw60jqkk3ux8r3ycl5t3rlvc9uzjeu0agfuss8x8qy',
        'npub10t8npnmqhpwx9w8k232kess7gqtdlr6kqjemdzf8jnughwqd0gwsez0924',
        'npub1r8343wqpra05l3jnc4jud4xz7vlnyeslf7gfsty7ahpf92rhfmpsmqwym8',
        'npub17fqtu2mgf7zueq2kdusgzwr2lqwhgfl2scjsez77ddag2qx8vxaq3vnr8y',
        'npub1v4lgwjv7qfn3t7qjscpsgz9vqvspf6hecdp2ckgp0dz89uqn5slsgrhw3p',
        'npub14r770s5wrqpm8jmzur5arnm9aum9x0wasaxwczael54xhjggl7ws5lygc6',
    ],

    /*
     * Relays, von denen Profile (kind 0) geholt werden.
     *
     * Konfigurierbar, weil der Abruf SYNCHRON im Request laeuft: Findet sich
     * kein Profil, wartet der Aufrufer auf die Zeitueberschreitung jedes
     * einzelnen Relays — gemessen 21 Sekunden fuer die vier Standardadressen.
     *
     * In Tests gehoert das auf eine leere Liste (NOSTR_PROFILE_RELAYS=""):
     * Ein Test darf keine echten Verbindungen nach draussen aufbauen, und ein
     * Wegwerf-Schluessel hat dort ohnehin nie ein Profil. Leer heisst: gar
     * nicht erst verbinden.
     */
    'profile_relays' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NOSTR_PROFILE_RELAYS', implode(',', [
            'wss://purplepag.es',
            'wss://nostr.wine',
            'wss://relay.damus.io',
            'wss://relay.primal.net',
        ])))
    ))),
];
