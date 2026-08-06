<?php

use App\Exceptions\Nip98Exception;
use App\Models\EinundzwanzigPleb;
use App\Support\Nip98;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;

/*
 * Every negative case here asserts twice.
 *
 * The HTTP assertion pins the contract: the caller sees 401 and nothing else.
 * The reason assertion pins WHY, by running the same event through
 * Nip98::verify() directly — because a 401 alone cannot tell "the signature
 * check caught it" from "an earlier guard caught it first". That distinction
 * is not academic: in P1 three negative cases were green while the protection
 * they claimed to test was never reached.
 */

const NIP98_TEST_CLIENT_KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    registerApiV1TestRoutes();

    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => NIP98_TEST_CLIENT_KEY,
    ]]);
});

/**
 * Run one event through the verifier outside the HTTP stack and return the
 * reason it was refused.
 *
 * @param  array<string, mixed>  $event
 */
function nip98Reason(array $event, string $url, string $method = 'GET', string $body = ''): string
{
    $request = Request::create(
        $url,
        $method,
        server: [
            'HTTP_AUTHORIZATION' => nip98Header($event),
            // Without this, Symfony defaults a POST to
            // application/x-www-form-urlencoded and every body case would
            // stop at the content-type guard instead of the one it tests.
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $body,
    );

    try {
        Nip98::verify($request);
    } catch (Nip98Exception $e) {
        return $e->reason;
    }

    return 'accepted';
}

/**
 * A GET with a genuinely empty body.
 *
 * Not getJson(): that helper JSON-encodes its (empty) data array and ships
 * "[]" as the body, so the request would need a `payload` tag no real GET
 * carries. The bytes on the wire are what the payload rule is about, so the
 * test has to put the real bytes on the wire.
 *
 * @param  array<string, mixed>  $event
 */
function nip98Get(array $event, ?string $url = null): TestResponse
{
    return test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => nip98Header($event),
        'Accept' => 'application/json',
    ])->get($url ?? '/api/v1/_ping');
}

/**
 * A POST whose body is exactly the bytes given.
 *
 * postJson() would re-encode the data and we could no longer say which bytes
 * were hashed into the `payload` tag. Headers go in as server vars because
 * TestCase::call() — unlike get()/postJson() — does not apply withHeaders()
 * defaults; passing them any other way silently drops the credentials and
 * every assertion below would collapse into "401, for some reason".
 *
 * @param  array<string, mixed>  $event
 */
function nip98Post(array $event, string $body, string $url = '/api/v1/_ping', string $contentType = 'application/json'): TestResponse
{
    return test()->call('POST', $url, server: [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => NIP98_TEST_CLIENT_KEY,
        'HTTP_AUTHORIZATION' => nip98Header($event),
    ], content: $body);
}

it('accepts a correctly signed NIP-98 request', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    nip98Get($signed['event'])
        ->assertSuccessful()
        ->assertJson([
            'client' => 'einundzwanzig-group',
            'pubkey' => $signed['pubkey'],
        ]);
});

it('rejects a tampered signature', function () {
    $signed = makeNip98Event(apiV1PingUrl());
    $event = $signed['event'];

    // Flip one hex nibble IN PLACE; id, kind, tags, timestamp and — since the
    // strict-hex guard was added — the signature's length and alphabet all
    // stay valid, so nothing before the signature check can catch this.
    // (An earlier version of this test truncated the signature to 64
    // characters and was therefore green for the wrong reason.)
    $event['sig'][63] = $event['sig'][63] === 'a' ? 'b' : 'a';

    nip98Get($event)->assertUnauthorized();

    expect(nip98Reason($event, apiV1PingUrl()))->toBe(Nip98Exception::INVALID_SIGNATURE);
});

it('rejects an event whose id was not derived from its own payload', function () {
    $signed = makeNip98Event(apiV1PingUrl());
    $event = $signed['event'];

    $event['id'] = hash('sha256', 'an id that belongs to no payload');

    nip98Get($event)->assertUnauthorized();

    expect(nip98Reason($event, apiV1PingUrl()))->toBe(Nip98Exception::INVALID_EVENT_ID);
});

it('rejects a validly signed event of the wrong kind', function () {
    // Signed as kind 27234, so signature and id are genuinely correct and the
    // kind check is provably the only thing standing in the way.
    $signed = makeNip98Event(apiV1PingUrl(), kind: 27234);

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::INVALID_KIND);
});

it('rejects an event signed for a different url', function () {
    $signed = makeNip98Event(url('/api/v1/_somewhere-else'));

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::URL_MISMATCH);
});

it('rejects an event signed for a different host', function () {
    // The absolute-URL rule is what stops an event signed against a
    // look-alike deployment from being relayed to the real one.
    $signed = makeNip98Event('http://evil.example/api/v1/_ping');

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::URL_MISMATCH);
});

it('rejects an event signed for a different method', function () {
    $signed = makeNip98Event(apiV1PingUrl(), 'DELETE');

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::METHOD_MISMATCH);
});

it('rejects an event outside the created_at window', function (int $offset) {
    // Frozen clock: signing a Schnorr event costs ~100 ms in pure PHP, enough
    // for an event stamped 61 s ahead to drift back INTO the window before the
    // assertion runs. The test would then pass or fail by stopwatch.
    test()->freezeTime();

    $signed = makeNip98Event(apiV1PingUrl(), createdAt: now()->timestamp + $offset);

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::STALE_EVENT);
})->with([
    'too old' => -61,
    'too far in the future' => 61,
]);

it('rejects a request whose body does not match the signed payload tag', function () {
    $signedBody = json_encode(['application_text' => 'the text I signed']);
    $sentBody = json_encode(['application_text' => 'the text that was sent']);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $signedBody);

    nip98Post($signed['event'], $sentBody)->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl(), 'POST', $sentBody))
        ->toBe(Nip98Exception::PAYLOAD_MISMATCH);
});

it('accepts a request whose body matches the signed payload tag', function () {
    $body = json_encode(['application_text' => 'the text I signed']);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body)->assertSuccessful();
});

/*
 * The multipart hole, and why these tests are built the way they are.
 *
 * Under a real SAPI, PHP parses a multipart/form-data body into $_POST before
 * any application code runs, and php://input — hence getContent() — is EMPTY.
 * A guard that skips the payload check on an empty raw body therefore skipped
 * it for a request full of attacker-chosen data. Measured against `php -S`
 * before the fix: an event signed for body A was accepted with a completely
 * different multipart body B (HTTP 200, raw "", $_POST carrying
 * angreifer@example.com).
 *
 * These tests do NOT spawn a server. They reproduce the exact state that SAPI
 * produces — parsed input present, raw body empty — by passing the request
 * bag and an empty body to the test client, and each one asserts that this
 * state really was reached before asserting the response. That is what makes
 * them trustworthy: the condition under test is verified, not assumed. (The
 * fix itself was additionally confirmed against `php -S` afterwards: 415
 * instead of 200.)
 */
function nip98RawPost(array $event, array $parsedInput, string $rawBody, string $contentType): TestResponse
{
    return test()->call('POST', '/api/v1/_ping', $parsedInput, [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => NIP98_TEST_CLIENT_KEY,
        'HTTP_AUTHORIZATION' => nip98Header($event),
    ], $rawBody);
}

it('reproduces the SAPI state a multipart body leaves behind', function () {
    // Guard for the two tests below: if this ever stops holding, they would
    // be testing an ordinary empty request and would pass for free.
    $request = Request::create('/x', 'POST', ['email' => 'angreifer@example.com'], server: [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=xxx',
    ], content: '');

    expect($request->getContent())->toBe('')
        ->and($request->request->count())->toBe(1)
        ->and($request->input('email'))->toBe('angreifer@example.com');
});

it('refuses a multipart body, signed payload tag or not', function (bool $withPayloadTag) {
    $signedBody = json_encode(['email' => 'echt@example.com']);

    // Both variants are tried because requiring the tag alone would not help:
    // sha256("") is a value an attacker can sign just as easily as any other.
    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $withPayloadTag ? $signedBody : null);

    nip98RawPost(
        $signed['event'],
        ['email' => 'angreifer@example.com'],
        '',
        'multipart/form-data; boundary=xxx'
    )->assertStatus(415);

    expect(EinundzwanzigPleb::count())->toBe(0);
})->with([
    'with a payload tag' => true,
    'without a payload tag' => false,
]);

it('refuses a content type Laravel would not read as JSON', function (string $contentType) {
    /*
     * Request::isJson() matches '/json' and '+json' CASE-SENSITIVELY, so
     * `Application/JSON` used to pass a case-insensitive guard while Laravel
     * refused to parse the body. Measured against a live server: HTTP 200,
     * raw body carrying the signed bytes, input array EMPTY. The signature
     * bound correctly and the endpoint still saw different data than the user
     * signed — a client operator could drop a signed field without breaking
     * the binding, and in P4 a missing optional field means something.
     */
    $body = json_encode(['application_text' => 'signed by the user']);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body, contentType: $contentType)->assertStatus(415);
})->with([
    'capitalised' => 'Application/JSON',
    'shouted' => 'APPLICATION/JSON',
    'a JSON dialect Laravel parses but we do not accept' => 'application/vnd.api+json',
]);

it('accepts the one content type that both sides agree on', function () {
    // Control: the guard would also be "green" if it rejected everything.
    $body = json_encode(['application_text' => 'signed by the user']);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body)->assertSuccessful();
});

it('refuses a form-encoded body', function () {
    $signed = makeNip98Event(apiV1PingUrl(), 'POST');

    nip98RawPost(
        $signed['event'],
        ['email' => 'angreifer@example.com'],
        'email=angreifer%40example.com',
        'application/x-www-form-urlencoded'
    )->assertStatus(415);
});

it('refuses input it cannot read even when the content type claims JSON', function () {
    /*
     * The backstop behind the content-type rule: input exists, the raw bytes
     * are gone, so there is nothing the signature could be checked against.
     *
     * Asserted against Nip98::verify() rather than over HTTP, and the reason
     * is worth stating: Laravel's Request::createFromBase() REPLACES the form
     * bag with the parsed JSON body whenever the content type is JSON, so no
     * request that reaches a controller can currently carry form input under
     * a JSON header. The branch is therefore unreachable through today's
     * stack — an HTTP test would have been green without ever running it. It
     * is kept as a guarantee of this function's contract, so that a future
     * change in body handling cannot turn "cannot verify" into "accept".
     */
    $signed = makeNip98Event(apiV1PingUrl(), 'POST');

    $request = Request::create(apiV1PingUrl(), 'POST', ['email' => 'angreifer@example.com'], server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => nip98Header($signed['event']),
    ], content: '');

    expect($request->getContent())->toBe('')
        ->and($request->request->count())->toBe(1);

    try {
        Nip98::verify($request);
        $reason = 'accepted';
    } catch (Nip98Exception $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe(Nip98Exception::UNREADABLE_BODY);
});

it('rejects an event whose id, pubkey or signature is not lowercase hex', function (string $field, callable $mutate) {
    $signed = makeNip98Event(apiV1PingUrl());
    $event = $signed['event'];
    $event[$field] = $mutate($event[$field]);

    nip98Get($event)->assertUnauthorized();

    expect(nip98Reason($event, apiV1PingUrl()))->toBe(Nip98Exception::MALFORMED_EVENT);
})->with([
    'uppercase pubkey' => ['pubkey', fn (string $v) => strtoupper($v)],
    'uppercase id' => ['id', fn (string $v) => strtoupper($v)],
    'uppercase signature' => ['sig', fn (string $v) => strtoupper($v)],
    'short pubkey' => ['pubkey', fn (string $v) => substr($v, 0, 63)],
    'non-hex pubkey' => ['pubkey', fn (string $v) => substr($v, 0, 63).'z'],
]);

it('rejects a genuinely valid event whose pubkey is spelled in uppercase', function () {
    /*
     * The real attack, not a shape violation: the event id is computed over
     * the pubkey STRING as delivered and the Schnorr verifier accepts
     * uppercase hex, so this event is cryptographically sound end to end.
     * Without the strict-hex guard it was accepted, and — the point of the
     * finding — it counted against a DIFFERENT rate-limiter bucket than the
     * lowercase spelling of the same key. Measured against `php -S` with a
     * limit of 2/min: requests 4 and 5 in uppercase sailed through after
     * request 3 had been throttled.
     */
    $signed = makeUppercasePubkeyNip98Event(apiV1PingUrl());

    // Proof that the event really is valid, so the rejection below cannot be
    // dismissed as "the signature was broken anyway".
    expect((new Event)->verify((object) $signed['event']))->toBeTrue()
        ->and($signed['event']['pubkey'])->toBe(strtoupper($signed['event']['pubkey']));

    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::MALFORMED_EVENT);
});

it('gives a case variant no rate-limit budget of its own', function () {
    config([
        'einundzwanzig.config.api_rate_limits.pubkey_per_minute' => 1,
        'einundzwanzig.config.api_rate_limits.client_per_minute' => 100,
    ]);

    $privkey = (new Key)->generatePrivateKey();

    nip98Get(makeNip98Event(apiV1PingUrl(), privkey: $privkey)['event'])->assertSuccessful();
    nip98Get(makeNip98Event(apiV1PingUrl(), privkey: $privkey)['event'])->assertStatus(429);

    // Before the fix this was a 200: a fresh bucket for the same key.
    nip98Get(makeUppercasePubkeyNip98Event(apiV1PingUrl(), $privkey)['event'])
        ->assertUnauthorized();
});

it('rejects an event signed for a foreign host even when the Host header agrees', function () {
    // The `u` tag is compared against config('app.url'), not against
    // $request->fullUrl(): fullUrl() takes scheme and host from the Host
    // header, which nothing validates. Measured against `php -S` before the
    // fix, this exact request answered 200 — a client operator could have his
    // users sign events for HIS domain and spend them here, and the host is
    // the one thing a user can check in a NIP-07 signing dialog.
    $signed = makeNip98Event('http://evil.example/api/v1/_ping');

    test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
        'Accept' => 'application/json',
    ])->get('http://evil.example/api/v1/_ping')->assertUnauthorized();
});

it('accepts an event signed for the configured url no matter what Host claims', function () {
    // The same coin's other side: the comparison no longer depends on the
    // header at all.
    $signed = makeNip98Event(apiV1PingUrl());

    test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
        'Accept' => 'application/json',
    ])->get('http://evil.example/api/v1/_ping')->assertSuccessful();
});

it('closes when the application url is unreadable', function (mixed $appUrl) {
    config(['app.url' => $appUrl]);

    $signed = makeNip98Event('http://localhost/api/v1/_ping');

    nip98Get($signed['event'])->assertServiceUnavailable();
})->with([
    'empty' => [''],
    'missing' => [null],
    'no scheme' => ['verein.einundzwanzig.space'],
]);

it('still rejects a differing query string', function () {
    // Only the ORIGIN comes from configuration; path and query still come
    // from the request and are still part of what was signed.
    $signed = makeNip98Event(apiV1PingUrl().'?year=2026');

    nip98Get($signed['event'], '/api/v1/_ping?year=2025')->assertUnauthorized();

    nip98Get(makeNip98Event(apiV1PingUrl().'?year=2026')['event'], '/api/v1/_ping?year=2026')
        ->assertSuccessful();
});

it('refuses a subject named "key" in the body', function () {
    // `key` is the spelling this repository already uses for a pubkey in a
    // route (routes/api.php: /nostr/profile/{key}).
    $foreign = (new Key)->getPublicKey((new Key)->generatePrivateKey());
    $body = json_encode(['key' => $foreign]);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body)->assertForbidden();
});

it('rejects a second use of the same event id', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    nip98Get($signed['event'])->assertSuccessful();
    nip98Get($signed['event'])->assertUnauthorized();

    expect(nip98Reason($signed['event'], apiV1PingUrl()))->toBe(Nip98Exception::REPLAYED);
});

it('rejects a request without any NIP-98 credential', function () {
    test()->withHeaders(['X-Api-Key' => NIP98_TEST_CLIENT_KEY])
        ->getJson('/api/v1/_ping')
        ->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('rejects an unparsable NIP-98 credential', function (string $header, string $reason) {
    test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => $header,
    ])->getJson('/api/v1/_ping')->assertUnauthorized();

    $request = Request::create(apiV1PingUrl(), 'GET', server: ['HTTP_AUTHORIZATION' => $header]);

    try {
        Nip98::verify($request);
        $actual = 'accepted';
    } catch (Nip98Exception $e) {
        $actual = $e->reason;
    }

    expect($actual)->toBe($reason);
})->with([
    'bearer scheme' => ['Bearer something', Nip98Exception::MISSING_AUTHORIZATION],
    'not base64' => ['Nostr !!!not-base64!!!', Nip98Exception::MALFORMED_AUTHORIZATION],
    'not json' => ['Nostr '.'bm90IGpzb24=', Nip98Exception::MALFORMED_AUTHORIZATION],
    'missing fields' => ['Nostr '.'eyJraW5kIjoyNzIzNX0=', Nip98Exception::MALFORMED_EVENT],
]);

it('refuses a subject in the path that differs from the signed pubkey', function () {
    $foreign = (new Key)->getPublicKey((new Key)->generatePrivateKey());
    $url = apiV1PingUrl('/'.$foreign);

    $signed = makeNip98Event($url, 'POST', '[]');

    test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->postJson('/api/v1/_ping/'.$foreign)->assertForbidden();
});

it('refuses a subject in the body that differs from the signed pubkey', function () {
    $foreign = (new Key)->getPublicKey((new Key)->generatePrivateKey());
    $body = json_encode(['pubkey' => $foreign]);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body)->assertForbidden();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('refuses a foreign npub in the body just as it refuses a foreign hex pubkey', function () {
    $key = new Key;
    $foreignNpub = $key->convertPublicKeyToBech32($key->getPublicKey($key->generatePrivateKey()));
    $body = json_encode(['npub' => $foreignNpub]);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', $body);

    nip98Post($signed['event'], $body)->assertForbidden();
});

it('accepts a subject in the path that is the signed pubkey', function () {
    $key = new Key;
    $privkey = $key->generatePrivateKey();
    $pubkey = $key->getPublicKey($privkey);

    $signed = makeNip98Event(apiV1PingUrl('/'.$pubkey), 'POST', '[]', privkey: $privkey);

    test()->withHeaders([
        'X-Api-Key' => NIP98_TEST_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->postJson('/api/v1/_ping/'.$pubkey)->assertSuccessful();
});
