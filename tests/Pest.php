<?php

use App\Http\Middleware\ThrottleApiV1;
use App\Models\EinundzwanzigPleb;
use App\Support\ApiIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| TIA replays cached results for tests unaffected by the current change and
| re-runs the rest. Deliberately NOT calling ->locally() or ->always():
| both auto-enable TIA for every run (locally() only skips it in CI —
| see Environment::LOCAL check in vendor/pestphp/pest/src/Plugins/Tia.php,
| handleArguments()/isEnabledForRun()), so either one would make even a
| bare `./vendor/bin/pest --compact` run in TIA mode. TIA stays strictly
| opt-in via the explicit `--tia` CLI flag. "filtered()" actually narrows
| the selection instead of only skipping replay — applies whenever --tia
| is passed.
|
| Most PHP source changes are resolved precisely by TIA's own coverage
| graph — no watch() pattern is needed or wanted for them, since a pattern
| can only broaden the selection back towards "whole directory", undoing
| the graph's precision. Verified empirically (--tia --fresh, then a real
| behavioural edit + `./vendor/bin/pest --tia`, reverted after each probe):
| app/Livewire, app/Policies, app/Http/Controllers/Nostr and
| config/einundzwanzig/config.php are all resolved to the exact dependent
| test file(s) by the graph alone.
|
| The two exceptions below exist because some source files never execute a
| single line during any test run (e.g. an Eloquent relation method nobody
| calls directly, only via the query builder) and so never enter the
| coverage graph. Unlike app/Policies, app/Providers, etc., "app/Models"
| and "app/Auth" are not covered by Pest's own sibling-directory fallback
| (see WatchDefaults and Graph::usesSiblingHeuristicForUnknownPhp() in
| vendor/pestphp/pest), so an edit to such a file would otherwise select
| zero affected tests. Confirmed live: a behavioural edit to
| app/Models/Vote.php::einundzwanzigPleb() and to
| app/Auth/NostrUserProvider.php::retrieveById() each produced an EMPTY
| affected set under --tia before these patterns were added.
|
| The target is the whole test path, not a single subdirectory: a given
| Model or Auth class is exercised from wherever it happens to be used
| (e.g. App\Models\Vote is asserted against from Policies, Livewire and
| rate-limiting tests alike), so a narrower target would just trade one
| blind spot for another.
|
| No ->baselined() on purpose. It fetches a dependency graph recorded by CI
| so a team can share one instead of each machine building its own — and it
| is wired specifically to GitHub Actions: BaselineSync expects a workflow
| named tia-baseline.yml producing an artifact "pest-tia-baseline" and pulls
| it via the `gh` CLI. This repo has no CI at all (no .github/, deployment
| runs through Forge), and the locally recorded graph already delivers the
| full ~22-25x replay speedup. Revisit only if CI is introduced.
|
| One trap worth knowing: TIA does NOT follow the symlinked sibling package
| vendor/einundzwanzig/group. An edit over in einundzwanzig-group selects
| zero affected tests here, so --tia would report a green replay that never
| exercised the change. Cause is a symlink/realpath mismatch between PHPUnit
| and Pest that no config in this repo fixes; use --no-tia (or a full run)
| while working on that package.
|
*/

pest()->tia()
    ->filtered()
    ->watch([
        'app/Models/**/*.php' => 'tests',
        'app/Auth/**/*.php' => 'tests',
    ]);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
| toBeNostrHexKey(): a NIP-01 pubkey/id/sig must be exactly 64 lowercase hex
| characters. toBeHexadecimal() alone accepts uppercase A-F (it wraps
| ctype_xdigit()), which NIP-01 forbids — the trailing toMatch() keeps the
| lowercase-only guarantee that toHaveLength()+toBeHexadecimal() would lose.
*/
expect()->extend('toBeNostrHexKey', function () {
    return $this->toHaveLength(64)->toBeHexadecimal()->toMatch('/^[0-9a-f]+$/');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build a NIP-42-style kind-22242 login event signed with a freshly generated
 * keypair. Returns the signed event as the plain array that the frontend
 * dispatches to Livewire (post-JSON round-trip), plus the pubkey for assertions.
 *
 * Lives here rather than in a single test file because more than one suite
 * needs a genuinely signed login: the auth tests themselves, and every test
 * that has to reach the state "logged in the way a real browser does".
 *
 * Pass `$privkey` to sign a second, different challenge with the same identity —
 * that is what a returning member's browser does on the next login.
 *
 * @return array{event: array<string, mixed>, pubkey: string, privkey: string}
 */
function makeSignedLoginEvent(string $challenge, ?int $createdAt = null, ?string $privkey = null): array
{
    $key = new Key;
    $privkey ??= $key->generatePrivateKey();
    $pubkey = $key->getPublicKey($privkey);

    $event = new Event;
    $event->setKind(22242);
    $event->setCreatedAt($createdAt ?? time());
    $event->setTags([['challenge', $challenge]]);
    $event->setContent('');

    (new Sign)->signEvent($event, $privkey);

    $array = $event->toArray();

    // Match the shape produced by JSON.parse(JSON.stringify(signedEvent)) in
    // nostrLogin.js — plain arrays, integer kind/created_at, string sig/id.
    return [
        'event' => [
            'id' => $array['id'],
            'pubkey' => $array['pubkey'],
            'created_at' => $array['created_at'],
            'kind' => $array['kind'],
            'tags' => $array['tags'],
            'content' => $array['content'],
            'sig' => $array['sig'],
        ],
        'pubkey' => $pubkey,
        'privkey' => $privkey,
    ];
}

/**
 * Build a NIP-98 kind-27235 HTTP-Auth event, signed with a freshly generated
 * keypair unless `$privkey` is passed.
 *
 * Deliberately a SECOND helper rather than a parameter on
 * makeSignedLoginEvent(): that one builds a kind-22242 login event bound to a
 * session challenge, the P1 auth tests depend on its exact shape, and the two
 * events share nothing but the signing call. Widening the login helper would
 * couple two mechanisms that P3 went out of its way to keep apart.
 *
 * The event is returned unencoded so a test can tamper with any single field
 * before handing it to nip98Header(). A random `nonce` tag keeps two events
 * for the same URL, method and second from colliding into one id — which the
 * replay lock would (correctly) reject; real signers face the same problem.
 *
 * @return array{event: array<string, mixed>, pubkey: string, privkey: string, header: string}
 */
function makeNip98Event(
    string $url,
    string $method = 'GET',
    ?string $body = null,
    ?int $createdAt = null,
    ?string $privkey = null,
    int $kind = 27235,
): array {
    $key = new Key;
    $privkey ??= $key->generatePrivateKey();
    $pubkey = $key->getPublicKey($privkey);

    $tags = [
        ['u', $url],
        ['method', strtoupper($method)],
        ['nonce', bin2hex(random_bytes(8))],
    ];

    if ($body !== null && $body !== '') {
        $tags[] = ['payload', hash('sha256', $body)];
    }

    $event = new Event;
    $event->setKind($kind);
    $event->setCreatedAt($createdAt ?? time());
    $event->setTags($tags);
    $event->setContent('');

    (new Sign)->signEvent($event, $privkey);

    $array = $event->toArray();

    $normalized = [
        'id' => $array['id'],
        'pubkey' => $array['pubkey'],
        'created_at' => $array['created_at'],
        'kind' => $array['kind'],
        'tags' => $array['tags'],
        'content' => $array['content'],
        'sig' => $array['sig'],
    ];

    return [
        'event' => $normalized,
        'pubkey' => $pubkey,
        'privkey' => $privkey,
        'header' => nip98Header($normalized),
    ];
}

/**
 * Wrap an event array in the `Authorization: Nostr <base64(JSON)>` form.
 *
 * @param  array<string, mixed>  $event
 */
function nip98Header(array $event): string
{
    return 'Nostr '.base64_encode(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * A NIP-98 event whose pubkey is spelled in UPPERCASE hex and which is
 * nevertheless cryptographically valid end to end.
 *
 * Built by hand rather than through swentel's Event, because that class
 * derives the pubkey from the private key and always emits it lowercase. The
 * id is computed over the serialised payload exactly as NIP-01 prescribes —
 * including the uppercase pubkey string — and then signed, so `Event::verify()`
 * accepts it: the id matches its own payload and the Schnorr verifier does not
 * care about hex case.
 *
 * That is precisely why case has to be rejected explicitly: one private key
 * would otherwise yield arbitrarily many valid identities, each with its own
 * rate-limiter bucket.
 *
 * @return array{event: array<string, mixed>, pubkey: string, privkey: string, header: string}
 */
function makeUppercasePubkeyNip98Event(string $url, ?string $privkey = null, string $method = 'GET'): array
{
    $key = new Key;
    $privkey ??= $key->generatePrivateKey();
    $pubkey = strtoupper($key->getPublicKey($privkey));

    $tags = [
        ['u', $url],
        ['method', strtoupper($method)],
        ['nonce', bin2hex(random_bytes(8))],
    ];
    $createdAt = time();

    $serialized = json_encode(
        [0, $pubkey, $createdAt, 27235, $tags, ''],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $id = hash('sha256', $serialized);

    $event = [
        'id' => $id,
        'pubkey' => $pubkey,
        'created_at' => $createdAt,
        'kind' => 27235,
        'tags' => $tags,
        'content' => '',
        'sig' => (new SchnorrSignature)->sign($privkey, $id)['signature'],
    ];

    return [
        'event' => $event,
        'pubkey' => $pubkey,
        'privkey' => $privkey,
        'header' => nip98Header($event),
    ];
}

/**
 * Register the placeholder routes the api.v1 middleware group is tested
 * against.
 *
 * P3 builds the auth layer, not the surface — the real /api/v1 endpoints are
 * P4. Rather than shipping a production endpoint nobody asked for, the tests
 * register their own. They are attached to BOTH the framework's `api` group
 * and `api.v1`, which is exactly what a route declared in routes/api.php with
 * `->middleware('api.v1')` will get: the prepended ThrottleRequests:api from
 * bootstrap/app.php first, then VerifyApiClient, VerifyNip98 and
 * ThrottleRequests:api-v1. The chain is therefore the real one, not a
 * hand-assembled imitation — ApiV1RouteWiringTest asserts as much by proving
 * both the outer throttle headers and the inner 401 are present.
 *
 * The handler writes to the database on purpose: it makes "the client key is
 * checked BEFORE any data operation" an observable fact rather than a claim
 * about middleware order.
 */
function registerApiV1TestRoutes(): void
{
    Route::prefix('api')->middleware(['api', 'api.v1'])->group(function () {
        Route::match(['get', 'post'], '/v1/_ping', function (Request $request) {
            $pubkey = ApiIdentity::pubkey($request);

            EinundzwanzigPleb::query()->firstOrCreate(
                ['pubkey' => $pubkey],
                ['npub' => (new Key)->convertPublicKeyToBech32($pubkey)]
            );

            return response()->json([
                'client' => ApiIdentity::client($request),
                'pubkey' => $pubkey,
            ]);
        })->name('api.v1.test.ping');

        Route::post('/v1/_ping/{pubkey}', fn (Request $request) => response()->json([
            'pubkey' => ApiIdentity::pubkey($request),
        ]))->name('api.v1.test.ping-subject');

        Route::post('/v1/_ping-invoice', fn (Request $request) => response()->json([
            'pubkey' => ApiIdentity::pubkey($request),
        ]))
            ->middleware(ThrottleApiV1::class.':api-v1-invoice')
            ->name('api.v1.test.ping-invoice');
    });
}

/**
 * The absolute URL a NIP-98 event must be signed for.
 *
 * Built with url() rather than hardcoded: the test client derives the request
 * URL from APP_URL too, and APP_URL differs per machine (":8000" locally).
 * A literal here would make the `u` comparison pass or fail depending on
 * whose checkout it runs in.
 */
function apiV1PingUrl(string $suffix = ''): string
{
    return url('/api/v1/_ping'.$suffix);
}

/**
 * Sign and send a GET to a real /api/v1 route the way a third-party client
 * would: client key in `X-Api-Key`, a freshly signed kind-27235 event in
 * `Authorization`.
 *
 * `$path` may carry a query string — it has to travel into the `u` tag as well
 * as into the request, because Nip98::expectedUrl() builds the signed URL from
 * the origin plus the full request URI. Signing the bare path and requesting
 * the one with the query would fail on `url_mismatch`, i.e. for a reason no
 * test here is about.
 *
 * `created_at` comes from now() rather than time() so the helper survives
 * travelTo(): the freshness window is measured against Carbon's clock, and a
 * real timestamp inside a test that has travelled a year would be refused as
 * stale before the endpoint was ever reached.
 *
 * Sent with get() and an Accept header, NOT with getJson(): getJson() encodes
 * its (empty) data array and ships a body of "[]". Nip98::carriesInput() then
 * correctly reports that the request carries something, demands the `payload`
 * tag over those two bytes, and every case here would fail on
 * `payload_mismatch` — green or red for a reason that has nothing to do with
 * what is being tested. A real client's GET has no body either.
 *
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function apiV1SignedGet(string $path, string $clientKey, ?string $privkey = null): array
{
    $signed = makeNip98Event(
        url($path),
        'GET',
        createdAt: now()->timestamp,
        privkey: $privkey,
    );

    $response = test()->withHeaders([
        'X-Api-Key' => $clientKey,
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ])->get($path);

    return [
        'response' => $response,
        'pubkey' => $signed['pubkey'],
        'privkey' => $signed['privkey'],
    ];
}

/**
 * The writing counterpart of apiV1SignedGet(): sign and send a POST or DELETE
 * to a real /api/v1 route the way a third-party client would.
 *
 * The body is encoded ONCE and the `payload` tag is built over exactly those
 * bytes, because that is what the signature covers — `Nip98::assertPayload()`
 * hashes the raw body, not the parsed array, and two different byte strings
 * can parse to the same array. `json()` re-encodes the same array with the
 * same flags, so what is signed and what is sent are byte-identical.
 *
 * `$body === null` means a request with NO body at all, which is what a client
 * sends to `/invoice`, `/refresh` and `DELETE /me`. It has to go through
 * post()/delete() rather than postJson()/deleteJson(): those encode their
 * (empty) data array and ship a body of "[]", whereupon carriesInput() quite
 * correctly demands a `payload` tag over those two bytes and every such test
 * fails on `payload_mismatch` instead of on what it was written to prove.
 *
 * @param  array<string, mixed>|null  $body
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function apiV1SignedRequest(
    string $method,
    string $path,
    string $clientKey,
    ?string $privkey = null,
    ?array $body = null,
): array {
    $method = strtoupper($method);
    $content = $body === null ? null : json_encode($body);

    $signed = makeNip98Event(
        url($path),
        $method,
        $content,
        createdAt: now()->timestamp,
        privkey: $privkey,
    );

    $request = test()->withHeaders([
        'X-Api-Key' => $clientKey,
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ]);

    $response = match (true) {
        $body !== null => $request->json($method, $path, $body),
        $method === 'DELETE' => $request->delete($path),
        default => $request->post($path),
    };

    return [
        'response' => $response,
        'pubkey' => $signed['pubkey'],
        'privkey' => $signed['privkey'],
    ];
}

function something()
{
    // ..
}
