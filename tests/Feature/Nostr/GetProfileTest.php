<?php

use App\Http\Controllers\Api\Nostr\GetProfile;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/nostr/profile/{key} — Einzelprofil, unauthentifiziert, LESEND.
 *
 * Der Endpunkt legte bis 2026-08-06 per `firstOrCreate()` einen `EinundzwanzigPleb` an.
 * Ein unauthentifizierter GET schrieb damit in die Mitgliedertabelle, die die
 * Vorstandsuebersicht und den CSV-Export speist — jeder beliebige Hex-String genuegte.
 * Diese Datei haelt beide Riegel fest: kein Schreiben, und kein Durchkommen fuer alles,
 * was kein NIP-01-Pubkey ist.
 */

/** 64 Zeichen Kleinbuchstaben-Hex — das einzige Pubkey-Format, das NIP-01 kennt. */
function profilePubkey(string $seed): string
{
    return hash('sha256', $seed);
}

/**
 * Ersetzt den Controller durch einen, der den Relay-Abruf nur PROTOKOLLIERT statt ihn
 * auszufuehren. Notwendig, weil `fetchProfile()` ueber `swentel/nostr` eine WebSocket-
 * Verbindung aufbaut und nicht ueber den HTTP-Client laeuft — `Http::assertNothingSent()`
 * allein wuerde eine Relay-Verbindung also gar nicht sehen und ein gruenes, aber
 * bedeutungsloses Ergebnis liefern.
 *
 * Laravel loest den Controller einer Route ueber den Container auf
 * (`Illuminate\Routing\Route::getController()` → `$container->make()`), eine
 * Container-Instanz greift hier also.
 */
function spyOnProfileFetch(): object
{
    $spy = new class extends GetProfile
    {
        /** @var array<int, array<int, string>> */
        public array $fetched = [];

        public function fetchProfile($npubs)
        {
            $this->fetched[] = $npubs;
        }
    };

    app()->instance(GetProfile::class, $spy);

    return $spy;
}

it('legt fuer einen unbekannten Pubkey keinen EinundzwanzigPleb an', function () {
    Http::fake();

    $pubkey = profilePubkey('unbekannt');

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    $this->assertDatabaseCount('einundzwanzig_plebs', 0);
    $this->assertDatabaseMissing('einundzwanzig_plebs', ['pubkey' => $pubkey]);
});

it('legt auch dann keinen EinundzwanzigPleb an, wenn das Profil existiert', function () {
    Http::fake();

    $pubkey = profilePubkey('bekannt');
    Profile::factory()->create(['pubkey' => $pubkey, 'name' => 'satoshi']);

    $this->getJson('/api/nostr/profile/'.$pubkey)
        ->assertOk()
        ->assertJsonFragment(['name' => 'satoshi']);

    $this->assertDatabaseCount('einundzwanzig_plebs', 0);
});

it('laesst bestehende Mitgliedersaetze unveraendert', function () {
    Http::fake();

    $pleb = EinundzwanzigPleb::factory()->create();
    $before = $pleb->fresh()->toArray();

    $this->getJson('/api/nostr/profile/'.$pleb->pubkey)->assertNotFound();

    $this->assertDatabaseCount('einundzwanzig_plebs', 1);
    expect($pleb->fresh()->toArray())->toEqual($before);
});

it('weist alles ab, was kein 64-stelliger Kleinbuchstaben-Hex ist', function (string $key) {
    Http::fake();

    $this->getJson('/api/nostr/profile/'.$key)->assertNotFound();

    $this->assertDatabaseCount('einundzwanzig_plebs', 0);
    Http::assertNothingSent();
})->with([
    'zu kurz (63)' => [substr(profilePubkey('kurz'), 0, 63)],
    'zu lang (65)' => [profilePubkey('lang').'a'],
    'Grossbuchstaben' => [strtoupper(profilePubkey('gross'))],
    'kein Hex' => [str_repeat('z', 64)],
    'npub statt hex' => ['npub1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq'],
    'Freitext' => ['testkey'],
    'leerer Rest' => ['0'],
    'SQL-artig' => ["' or 1=1--"],
]);

it('erreicht den Controller bei ungueltigem Key gar nicht erst', function () {
    Http::fake();
    $spy = spyOnProfileFetch();

    $this->getJson('/api/nostr/profile/testkey')->assertNotFound();

    expect($spy->fetched)->toBe([]);
    Http::assertNothingSent();
});

it('holt fuer einen gueltigen, unbekannten Pubkey genau einen Relay-Abruf', function () {
    Http::fake();
    $spy = spyOnProfileFetch();

    $pubkey = profilePubkey('gueltig-unbekannt');

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    // Gegenprobe zum Test darueber: derselbe Spion zeichnet hier sehr wohl auf,
    // ein leeres `fetched` dort ist also die Route-Constraint und kein toter Spion.
    expect($spy->fetched)->toBe([[$pubkey]]);
});

it('fragt die Relays nicht noch einmal, wenn das Profil bereits gespiegelt ist', function () {
    Http::fake();
    $spy = spyOnProfileFetch();

    $pubkey = profilePubkey('gespiegelt');
    Profile::factory()->create(['pubkey' => $pubkey]);

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertOk();

    expect($spy->fetched)->toBe([]);
});

it('antwortet mit 404 statt mit 200, wenn kein Profil existiert', function () {
    Http::fake();

    $this->getJson('/api/nostr/profile/'.profilePubkey('kein-profil'))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Profile not found']);
});
