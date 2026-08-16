<?php

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Models\EinundzwanzigPleb;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * Der App-Zweig der Mitgliedschafts-API: Client-Key OHNE NIP-98, das
 * Subjekt kommt aus dem Body. Getestet wird genau der Vertrag aus
 * routes/api.php — drei Endpunkte, keine Lese-Fläche, keine Session.
 */

const APP_CLIENT_KEY = 'app111111111111111111111111111111111111111111111111111111app1';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-app' => APP_CLIENT_KEY],
        'einundzwanzig.config.membership_fee' => 21000,
        'einundzwanzig.config.currency' => 'SATS',
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
        'einundzwanzig.config.invoice_return_urls' => ['http://127.0.0.1/verein/zurueck'],
        'app.debug' => false,
    ]);

    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'app-inv-1',
        'checkoutLink' => 'https://pay.einundzwanzig.space/i/app-inv-1',
        'status' => 'New',
    ])]);
});

/**
 * Ein Application-Call ohne Signatur, nur mit Client-Key und Body.
 *
 * @param  array<string, mixed>|null  $body
 */
function appCall(string $path, ?array $body = null): TestResponse
{
    $request = test()->withHeaders([
        'X-Api-Key' => APP_CLIENT_KEY,
        'Accept' => 'application/json',
    ]);

    return $body !== null
        ? $request->postJson($path, $body)
        : $request->getJson($path);
}

it('refuses an application without a client key', function () {
    $pubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    $this->postJson('/api/v1/app/membership/applications', [
        'pubkey' => $pubkey,
        'statutes_accepted' => true,
    ])->assertUnauthorized();

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('records a first application with consent from the body pubkey', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    $response = appCall('/api/v1/app/membership/applications', [
        'pubkey' => $pubkey,
        'statutes_accepted' => true,
        'application_text' => 'Aus der App',
    ]);

    $response->assertCreated();

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->first();

    expect($pleb)->not->toBeNull()
        ->and($pleb->statutes_accepted_at)->not->toBeNull()
        ->and($pleb->application_text)->toBe('Aus der App');
});

it('answers a repeat application with 200 and leaves the consent untouched', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'statutes_accepted_at' => now()->subDays(3),
    ]);

    $first = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->first();

    appCall('/api/v1/app/membership/applications', [
        'pubkey' => $pubkey,
        'statutes_accepted' => true,
    ])->assertOk();

    expect($first->fresh()->statutes_accepted_at->equalTo($first->statutes_accepted_at))->toBeTrue();
});

it('refuses a pubkey in any other spelling', function () {
    appCall('/api/v1/app/membership/applications', [
        'pubkey' => strtoupper((new Key)->getPublicKey((new Key)->generatePrivateKey())),
        'statutes_accepted' => true,
    ])->assertUnprocessable();

    appCall('/api/v1/app/membership/applications', [
        'pubkey' => 'npub1placeholder',
        'statutes_accepted' => true,
    ])->assertUnprocessable();

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('refuses a first application without accepted statutes', function () {
    $pubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    appCall('/api/v1/app/membership/applications', [
        'pubkey' => $pubkey,
        'statutes_accepted' => false,
    ])->assertUnprocessable();

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('hands out an invoice for a body pubkey without any signature', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);

    $response = appCall('/api/v1/app/membership/payments/'.now()->year.'/invoice', [
        'pubkey' => $pubkey,
        'return_url' => 'http://127.0.0.1/verein/zurueck',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.checkout_url', 'https://pay.einundzwanzig.space/i/app-inv-1');
});

it('refuses an invoice for a pubkey without a record, without saying which part failed', function () {
    $pubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    appCall('/api/v1/app/membership/payments/'.now()->year.'/invoice', [
        'pubkey' => $pubkey,
    ])->assertNotFound()
        ->assertJsonPath('message', ApiV1Controller::NOT_FOUND_MESSAGE);

    Http::assertNothingSent();
});

it('refuses a return_url that is not on the allowlist', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);

    appCall('/api/v1/app/membership/payments/'.now()->year.'/invoice', [
        'pubkey' => $pubkey,
        'return_url' => 'https://evil.example/verein/zurueck',
    ])->assertUnprocessable();

    Http::assertNothingSent();
});

it('has no read surface: /me answers 404 on the app branch', function () {
    $this->withHeaders([
        'X-Api-Key' => APP_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/app/membership/me')->assertNotFound();
});

it('counts the invoice quota per body pubkey', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);

    $path = '/api/v1/app/membership/payments/'.now()->year.'/invoice';

    appCall($path, ['pubkey' => $pubkey])->assertOk();
    appCall($path, ['pubkey' => $pubkey])->assertOk();
    appCall($path, ['pubkey' => $pubkey])->assertOk();
    appCall($path, ['pubkey' => $pubkey])->assertTooManyRequests();

    /*
     * Und ein ANDERER Body-Pubkey hat seinen eigenen Eimer — der Fallback
     * aus AppServiceProvider::limiterPubkey() ist keine gemeinschaftliche
     * IP-Falle fuer alle App-Nutzer.
     */
    $other = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    EinundzwanzigPleb::factory()->create([
        'pubkey' => $other,
        'npub' => (new Key)->convertPublicKeyToBech32($other),
    ]);

    appCall($path, ['pubkey' => $other])->assertOk();
});
