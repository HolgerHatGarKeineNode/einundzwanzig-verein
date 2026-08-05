<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Einundzwanzig\Group\Nostr\ProfileCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * GET /nostr/profiles — Profil-Seed der Chat-Insel (rohe kind-0-Events, NIP-01).
 *
 * Kein Test darf ein Relay anfassen: Entweder liegt das Event schon im Cache
 * (dann holt ProfileCache nichts) oder ProfileCache ist durch die Attrappe
 * unten ersetzt, die zusätzlich mitschreibt, WAS beim Cache ankommt.
 */
beforeEach(function () {
    RateLimiter::clear('nostr-profiles');
});

/** Ein vollständiges, rohes kind-0-Event, wie ein Relay es liefert. */
function rawProfileEvent(string $pubkey, string $name): stdClass
{
    return (object) [
        'id' => str_repeat('a', 64),
        'pubkey' => $pubkey,
        'created_at' => 1700000000,
        'kind' => 0,
        'tags' => [],
        'content' => json_encode(['name' => $name]),
        'sig' => str_repeat('b', 128),
    ];
}

function hex64(string $seed): string
{
    return substr(hash('sha256', $seed), 0, 64);
}

/** Attrappe statt echtem Relay-Abruf: merkt sich die übergebenen pubkeys. */
function fakeProfileCache(array $events = []): object
{
    $fake = new class($events) extends ProfileCache
    {
        /** @var array<int, array<int, string>> */
        public array $calls = [];

        public function __construct(private array $events) {}

        public function get(array $pubkeys): array
        {
            $this->calls[] = $pubkeys;

            return $this->events;
        }
    };

    app()->instance(ProfileCache::class, $fake);

    return $fake;
}

function loginPleb(): EinundzwanzigPleb
{
    $pleb = EinundzwanzigPleb::factory()->create();
    NostrAuth::login($pleb->pubkey);

    return $pleb;
}

it('liefert die rohen kind-0-Events unter dem Schlüssel events', function () {
    loginPleb();

    // Echter ProfileCache, Treffer vorgewärmt → kein Relay-Zugriff.
    // Der Schlüssel hängt an ProfileCache::cacheKey() (Relay-Quellen-Namensraum,
    // s. dortiger Docblock) — nie von Hand nachbauen, sonst läuft der Test am
    // Cache vorbei und fällt still auf einen echten Relay-Zugriff zurück.
    $pubkey = hex64('vorstand-1');
    Cache::put(ProfileCache::cacheKey($pubkey), rawProfileEvent($pubkey, 'Vorstand'), 60);

    $response = $this->getJson('/nostr/profiles?pubkeys='.$pubkey);

    $response->assertOk();
    // Der Client prüft die Signatur selbst — es müssen ALLE Event-Felder ankommen.
    $response->assertJsonPath('events.0.pubkey', $pubkey);
    $response->assertJsonPath('events.0.kind', 0);
    $response->assertJsonPath('events.0.sig', str_repeat('b', 128));
    expect(array_keys($response->json('events.0')))
        ->toEqualCanonicalizing(['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig']);
});

/**
 * Kein Anmelde-Gate — und das ist Absicht, kein Versehen.
 *
 * kind-0-Events sind auf Nostr öffentlich; wer sie will, holt sie direkt beim
 * Relay. Eine Anmeldung davor verheimlicht nichts, kostet aber etwas: Läuft die
 * Sitzung ab, schluckt `seedChunk` den fehlgeschlagenen Abruf wortlos, und im
 * Chat verschwinden still die Namen. Gegen die reale Sorge — serverseitige
 * Relay-Arbeit für Fremde — wirkt die Drosselung, nicht ein Login.
 *
 * Der Test hält das fest, damit niemand das Gate "zur Sicherheit" nachrüstet.
 */
it('bedient auch Gäste, weil kind-0 öffentlich ist', function () {
    $event = rawProfileEvent(hex64('offen'), 'Öffentlich');
    $fake = fakeProfileCache([$event]);

    $response = $this->getJson('/nostr/profiles?pubkeys='.hex64('offen'));

    $response->assertSuccessful();
    expect($response->json('events.0.pubkey'))->toBe(hex64('offen'))
        ->and($fake->calls)->toBe([[hex64('offen')]]);
});

it('lässt genau 100 pubkeys durch', function () {
    loginPleb();
    $fake = fakeProfileCache();

    $pubkeys = array_map(fn (int $i) => hex64('pk'.$i), range(1, 100));

    $this->getJson('/nostr/profiles?pubkeys='.implode(',', $pubkeys))->assertOk();

    expect($fake->calls[0])->toHaveCount(100);
});

it('lehnt mehr als 100 pubkeys ab, ohne das Relay zu bemühen', function () {
    loginPleb();
    $fake = fakeProfileCache();

    $pubkeys = array_map(fn (int $i) => hex64('pk'.$i), range(1, 101));

    $this->getJson('/nostr/profiles?pubkeys='.implode(',', $pubkeys))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Too many pubkeys, maximum is 100.');

    expect($fake->calls)->toBe([]);
});

it('verwirft ungültige pubkeys still und bedient den Rest', function () {
    loginPleb();

    $gut = hex64('gut');
    $fake = fakeProfileCache([rawProfileEvent($gut, 'Gut')]);

    $response = $this->getJson('/nostr/profiles?pubkeys='.implode(',', [
        'nicht-hex',
        strtoupper($gut),      // Großschreibung ist kein gültiger hex-pubkey
        substr($gut, 0, 63),   // zu kurz
        $gut,
    ]));

    $response->assertOk();
    $response->assertJsonPath('events.0.pubkey', $gut);
    // Nur der gültige Schlüssel darf beim Relay-Cache landen.
    expect($fake->calls)->toBe([[$gut]]);
});

it('antwortet auf eine leere Anfrage mit einer leeren Liste', function (string $query) {
    loginPleb();
    $fake = fakeProfileCache();

    $this->getJson('/nostr/profiles'.$query)
        ->assertOk()
        ->assertExactJson(['events' => []]);

    expect($fake->calls)->toBe([]);
})->with([
    'ohne Parameter' => '',
    'leerer Parameter' => '?pubkeys=',
    'nur Kommas' => '?pubkeys=,,',
    'nur ungültige Schlüssel' => '?pubkeys=abc,nicht-hex',
]);

it('drosselt auf 30 Anfragen pro Minute', function () {
    loginPleb();
    fakeProfileCache();

    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/nostr/profiles?pubkeys='.hex64('pk'.$i))->assertOk();
    }

    $this->getJson('/nostr/profiles?pubkeys='.hex64('pk-zuviel'))->assertStatus(429);
});
