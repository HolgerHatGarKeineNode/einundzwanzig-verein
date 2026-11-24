<?php

use App\Http\Controllers\Api\Nostr\GetProfile;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Providers\AppServiceProvider;
use App\Services\Nostr\NostrRelayTransport;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRelayTransport;

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
 * One in-memory profile relay that records each request instead of connecting.
 * Needed because the relay lookup goes over a WebSocket, not the HTTP client:
 * `Http::assertNothingSent()` alone would not see a relay connection and give a
 * green but meaningless result.
 *
 * Returns a closure that lists the authors of each request in order.
 */
function spyOnProfileFetch(): Closure
{
    config(['einundzwanzig.config.profile_relays' => ['wss://profiles.example.com']]);
    $relay = FakeRelayTransport::bind();

    return fn (): array => array_map(fn (array $call): array => $call['filter']['authors'], $relay->fetched());
}

it('legt fuer einen unbekannten Pubkey keinen EinundzwanzigPleb an', function () {
    Http::fake([]);

    $pubkey = profilePubkey('unbekannt');

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    $this->assertDatabaseCount('einundzwanzig_plebs', 0);
    $this->assertDatabaseMissing('einundzwanzig_plebs', ['pubkey' => $pubkey]);
});

it('legt auch dann keinen EinundzwanzigPleb an, wenn das Profil existiert', function () {
    Http::fake([]);

    $pubkey = profilePubkey('bekannt');
    Profile::factory()->create(['pubkey' => $pubkey, 'name' => 'satoshi']);

    $this->getJson('/api/nostr/profile/'.$pubkey)
        ->assertOk()
        ->assertJsonFragment(['name' => 'satoshi']);

    $this->assertDatabaseCount('einundzwanzig_plebs', 0);
});

it('laesst bestehende Mitgliedersaetze unveraendert', function () {
    Http::fake([]);

    $pleb = EinundzwanzigPleb::factory()->create();
    $before = $pleb->fresh()->toArray();

    $this->getJson('/api/nostr/profile/'.$pleb->pubkey)->assertNotFound();

    $this->assertDatabaseCount('einundzwanzig_plebs', 1);
    expect($pleb->fresh()->toArray())->toEqual($before);
});

it('weist alles ab, was kein 64-stelliger Kleinbuchstaben-Hex ist', function (string $key) {
    Http::fake([]);

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
    Http::fake([]);
    $fetched = spyOnProfileFetch();

    $this->getJson('/api/nostr/profile/testkey')->assertNotFound();

    expect($fetched())->toBe([]);
    Http::assertNothingSent();
});

it('holt fuer einen gueltigen, unbekannten Pubkey genau einen Relay-Abruf', function () {
    Http::fake([]);
    $fetched = spyOnProfileFetch();

    $pubkey = profilePubkey('gueltig-unbekannt');

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    // Gegenprobe zum Test darueber: derselbe Spion zeichnet hier sehr wohl auf,
    // ein leeres `fetched` dort ist also die Route-Constraint und kein toter Spion.
    expect($fetched())->toBe([[$pubkey]]);
});

it('fragt die Relays nicht noch einmal, wenn das Profil bereits gespiegelt ist', function () {
    Http::fake([]);
    $fetched = spyOnProfileFetch();

    $pubkey = profilePubkey('gespiegelt');
    Profile::factory()->create(['pubkey' => $pubkey]);

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertOk();

    expect($fetched())->toBe([]);
});

it('antwortet mit 404 statt mit 200, wenn kein Profil existiert', function () {
    Http::fake([]);

    $this->getJson('/api/nostr/profile/'.profilePubkey('kein-profil'))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Profile not found']);
});

it('does not ask the relays again for a pubkey they did not deliver within ten minutes', function () {
    Http::fake([]);
    $fetched = spyOnProfileFetch();

    $pubkey = profilePubkey('miss-cached');

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();
    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    expect($fetched())->toBe([[$pubkey]]);

    $this->travel(11)->minutes();

    $this->getJson('/api/nostr/profile/'.$pubkey)->assertNotFound();

    expect($fetched())->toBe([[$pubkey], [$pubkey]]);
});

it('waits two seconds per relay instead of the vendor default of 60', function () {
    // The production binding is registered outside `testing` only; register it as production would.
    app()->detectEnvironment(fn (): string => 'production');
    (new AppServiceProvider(app()))->register();
    $controller = app(GetProfile::class);
    app()->detectEnvironment(fn (): string => 'testing');

    $fetcher = (fn () => $this->profiles)->call($controller);
    $transport = (fn () => $this->transport)->call($fetcher);

    expect($transport)->toBeInstanceOf(NostrRelayTransport::class)
        ->and((fn () => $this->deadlineSeconds)->call($transport))->toBe(GetProfile::RELAY_DEADLINE_SECONDS)
        ->and(GetProfile::RELAY_DEADLINE_SECONDS)->toBe(2.0);
});
