<?php

use App\Http\Middleware\VerifyNip98;
use App\Providers\ApiDocumentationServiceProvider;
use App\Support\OpenApi\MembershipApiDocumentTransformer;
use Illuminate\Routing\Router;
use swentel\nostr\Key\Key;

/*
 * The OpenAPI document for /api/v1 and the reference that renders it.
 *
 * Two things are pinned here, and neither is "Scramble works".
 *
 * THE REACH OF THE PAGE. The documentation route is public by decision
 * (config/scramble.php, `middleware`), so a broken gate would not show up as a
 * failing request anywhere — only as a page that quietly stopped being
 * reachable, or as one that quietly started phoning home. The tests therefore
 * assert both directions: it answers without a credential, and it fetches
 * nothing from a host that is not this one.
 *
 * THE COMMITTED ARTIFACT. `docs/openapi/v1.json` is what a third party
 * implements against, and it is a file in the repository rather than something
 * generated on demand. A file can be edited by hand, can be re-exported from a
 * branch that had extra routes, or can go stale. What must never change
 * silently is its perimeter: the eleven documented operations, both
 * authentication schemes, and the warning about `association_status` — the one
 * field on this API a consumer can get wrong without noticing.
 */

/**
 * The exported document, decoded.
 *
 * @return array<string, mixed>
 */
function exportedOpenApiDocument(): array
{
    $path = base_path('docs/openapi/v1.json');

    expect(file_exists($path))->toBeTrue(
        'docs/openapi/v1.json is missing. Run: php artisan scramble:export --path=docs/openapi/v1.json --api=v1'
    );

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray('docs/openapi/v1.json is not valid JSON.');

    return $decoded;
}

/**
 * Every human-readable string in the document, with the path it sits at.
 *
 * Walks the decoded document rather than a list of known fields, because the
 * point of the checks that use it is to catch text nobody put there on
 * purpose — a comment that travelled out of a PHP file into the public
 * document without anyone deciding so.
 *
 * @param  array<string, mixed>|list<mixed>  $node
 * @return list<array{0: string, 1: string}>
 */
function openApiProseFields(array $node, string $path = ''): array
{
    $found = [];

    foreach ($node as $key => $value) {
        $childPath = $path.'.'.$key;

        if (in_array($key, ['description', 'summary'], true) && is_string($value)) {
            $found[] = [$childPath, $value];

            continue;
        }

        if (is_array($value)) {
            $found = array_merge($found, openApiProseFields($value, $childPath));
        }
    }

    return $found;
}

/**
 * The eleven operations this API consists of — eight on the NIP-98 surface and
 * three on the app branch — method and path exactly as the document has to
 * spell them.
 *
 * @return list<array{0: string, 1: string}>
 */
function documentedApiV1Operations(): array
{
    return [
        ['get', '/api/v1/membership/config'],
        ['get', '/api/v1/membership/me'],
        ['delete', '/api/v1/membership/me'],
        ['get', '/api/v1/membership/payments'],
        ['get', '/api/v1/membership/export'],
        ['post', '/api/v1/membership/applications'],
        ['post', '/api/v1/membership/payments/{year}/invoice'],
        ['post', '/api/v1/membership/payments/{year}/refresh'],
        ['get', '/api/v1/app/membership/config'],
        ['post', '/api/v1/app/membership/applications'],
        ['post', '/api/v1/app/membership/payments/{year}/invoice'],
    ];
}

beforeEach(function () {
    /*
     * The generated document may be served from a warm cache
     * (`php artisan scramble:cache`), and that cache lives in the file store —
     * the same one a developer's machine already uses. Disabled here so these
     * tests measure the code in the working tree rather than whatever was
     * cached before it.
     */
    config(['scramble.cache.store' => null]);
});

it('serves the versioned document under the path the package would have claimed', function () {
    /*
     * `docs/api` is the path the PACKAGE registers its own `default` API on.
     * `ApiDocumentationServiceProvider` withdraws it with `expose(false)` and
     * publishes the `v1` API there instead. If that withdrawal ever stops
     * working — a Scramble release moving when its routes are registered would
     * do it — nothing throws and nothing 404s.
     *
     * WHAT WOULD BE LOST IS THE TRANSFORMER, NOT THE PATHS, and getting that
     * wrong is what made two earlier versions of this test worthless. The
     * default API is handed our own `config('scramble')`, `api_path` included,
     * so its document covers the same eleven `api/v1` operations ours does —
     * measured: ten paths, neither legacy endpoint among them. What it does
     * not carry is `MembershipApiDocumentTransformer`, which is registered on
     * the `v1` API alone. A package win therefore serves a document with the
     * right paths and NO `security`, no tags and none of the prose.
     *
     * So the assertion is on something only the transformer produces. The two
     * discarded approaches, and why each was vacuous:
     *   - Counting routes and comparing names. A `RouteCollection` is keyed by
     *     method+URI and OVERWRITES on collision
     *     (Illuminate\Routing\RouteCollection::addToCollections), so the count
     *     is structurally pinned to one; and the loser keeps its entry in the
     *     name list, so the name lookup passes as well. Both stayed green
     *     through a simulated takeover that served a foreign body.
     *   - Asserting on the SET OF PATHS. Green against the untransformed
     *     document, because that document has the very same paths.
     */
    $response = $this->get('/'.ApiDocumentationServiceProvider::DOCUMENT_PATH);

    $response->assertOk();

    expect($response->json('paths./api/v1/app/membership/applications.post.security'))
        ->toBe([[MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME => []]],
            'The document served under '.ApiDocumentationServiceProvider::DOCUMENT_PATH.' did not pass through '
            .'MembershipApiDocumentTransformer. Something other than the `v1` API is answering that path.');

    /*
     * And the reference renders that same document rather than a second one
     * some other route might be serving. It embeds it as `@json($spec)`, so
     * the content appears slash-escaped (`\/api\/v1\/...`) — searched for in
     * the form json_encode actually produces rather than in the form it has in
     * the document, which is why the plain spelling finds nothing here.
     *
     * The tag is transformer-only for the same reason as above: Scramble left
     * to itself tags an operation with its controller's class name. Asserted
     * positively rather than as the absence of `StoreAppApplication` — that
     * string is also the stem of the request-body SCHEMA name, which the
     * document carries either way.
     */
    $embedded = fn (string $value): string => trim((string) json_encode($value), '"');

    $this->get('/'.ApiDocumentationServiceProvider::UI_PATH)
        ->assertOk()
        ->assertSee($embedded('/api/v1/app/membership/applications'), escape: false)
        ->assertSee($embedded('Native app'), escape: false);
});

it('serves the reference without any credential', function () {
    $response = $this->get('/'.ApiDocumentationServiceProvider::UI_PATH);

    $response->assertOk();

    /*
     * No X-Api-Key, no signature, no session — and deliberately so. The point
     * of the assertion is the absence of the headers above, not the 200.
     */
    $response->assertSee('createApiReference', escape: false);
});

it('serves the machine-readable document without any credential', function () {
    $response = $this->get('/'.ApiDocumentationServiceProvider::DOCUMENT_PATH);

    $response->assertOk();

    expect($response->json('openapi'))->toBe('3.1.0')
        ->and($response->json('paths'))->toHaveKey('/api/v1/membership/me');
});

it('loads nothing from a third-party host', function () {
    $html = $this->get('/'.ApiDocumentationServiceProvider::UI_PATH)->getContent();

    /*
     * The three hosts the package would have reached for on its own: jsDelivr
     * for the bundle, proxy.scalar.com for every "Test Request" a reader fires.
     * Named literally, because a regex over asset URLs would pass a day after
     * somebody put one of them into an inline string instead.
     */
    expect($html)->not->toContain('cdn.jsdelivr.net')
        ->and($html)->not->toContain('proxy.scalar.com')
        ->and($html)->not->toContain('scalar.com');
});

it('turns off the reference webfonts', function () {
    $html = (string) $this->get('/'.ApiDocumentationServiceProvider::UI_PATH)->getContent();

    /*
     * The one third-party request the assertions above cannot see. Left at its
     * default, the reference writes @font-face rules for
     * `https://fonts.scalar.com` into the page AFTER it has loaded, so nothing
     * in the served HTML mentions the host and every string assertion passes
     * while three woff2 files travel to a vendor. Measured with a headless
     * browser before this flag was set — three requests — and again after:
     * none.
     *
     * Pinned on the CONFIGURATION rather than on the requests, and the reason
     * is a cost, not a rule: the plan does foresee a browser smoke test for
     * exactly this page (it rules browser tests out only for the API
     * endpoints, where postJson() is the sharper instrument). Observing the
     * font requests would mean adding a browser dependency to the suite for a
     * single assertion, and that was not worth it here.
     *
     * WHAT THIS THEREFORE DOES NOT CATCH: a Scalar release that RENAMES the
     * key. `withDefaultFonts` is `z.boolean().optional().default(true)` — the
     * default falls open, so an unknown key silently brings the fonts back and
     * this assertion still passes. That gap is closed elsewhere, by pinning
     * @scalar/api-reference to an exact version (see resources/js/scalar.js);
     * the two guards only work together.
     */
    expect($html)->toContain('"withDefaultFonts":false');
});

it('references only same-origin assets', function () {
    $html = (string) $this->get('/'.ApiDocumentationServiceProvider::UI_PATH)->getContent();

    preg_match_all('/(?:src|href)="([^"]+)"/i', $html, $matches);

    $external = array_values(array_filter(
        $matches[1],
        fn (string $url): bool => (bool) preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url)
            && ! str_starts_with($url, url('/')),
    ));

    expect($external)->toBe([], 'The reference must not load assets from another origin.');
});

it('exports a 3.1.0 document', function () {
    expect(exportedOpenApiDocument()['openapi'])->toBe('3.1.0');
});

it('documents exactly the eleven /api/v1 endpoints', function () {
    $paths = exportedOpenApiDocument()['paths'];

    $documented = [];

    foreach ($paths as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = [$method, $path];
        }
    }

    expect($documented)->toEqualCanonicalizing(documentedApiV1Operations());
});

it('leaves the two unversioned legacy endpoints out', function () {
    $paths = array_keys(exportedOpenApiDocument()['paths']);

    /*
     * They predate this API and are not part of it. The exclusion is a
     * consequence of the `api/v1/*` pattern in config/scramble.php, which is
     * exactly the kind of setting that gets widened by accident.
     */
    expect($paths)->not->toContain('/api/members/{year}')
        ->and($paths)->not->toContain('/api/nostr/profile/{key}');

    foreach ($paths as $path) {
        /*
         * Zwei Praefixe sind erlaubt: der NIP-98-Zweig und der App-Zweig
         * (Subjekt im Body). Alles andere waere eine dritte Flaeche, die es
         * nicht gibt.
         */
        expect($path)->toMatch('/^\/api\/v1\/(app\/)?membership/')
            ->not->toStartWith('/api/v1/membership-app');
    }
});

it('documents both authentication schemes', function () {
    $schemes = exportedOpenApiDocument()['components']['securitySchemes'];

    expect($schemes)->toHaveKeys([
        MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME,
        MembershipApiDocumentTransformer::NIP98_SCHEME,
    ]);

    $clientKey = $schemes[MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME];

    expect($clientKey['type'])->toBe('apiKey')
        ->and($clientKey['in'])->toBe('header')
        ->and($clientKey['name'])->toBe('X-Api-Key');

    expect($schemes[MembershipApiDocumentTransformer::NIP98_SCHEME]['type'])->toBe('http');
});

it('carries a NIP-98 example complete enough to build an event from', function () {
    $description = exportedOpenApiDocument()['components']['securitySchemes'][MembershipApiDocumentTransformer::NIP98_SCHEME]['description'];

    /*
     * Every rule App\Support\Nip98 enforces has to be findable in the
     * description, because a client developer cannot discover any of them from
     * a failing request: every one of them answers the same 401.
     */
    expect($description)->toContain('27235')
        ->and($description)->toContain('60 seconds')
        ->and($description)->toContain('150 seconds')
        ->and($description)->toContain('SHA-256')
        ->and($description)->toContain('Schnorr')
        ->and($description)->toContain('Authorization: Nostr')
        ->and($description)->toContain("['u', url]")
        ->and($description)->toContain("['method', method]")
        ->and($description)->toContain('payload')
        ->and($description)->toContain('btoa(JSON.stringify(event))')
        ->and($description)->toContain('application/json');
});

/**
 * Which `[method, /uri]` operations the SERVER verifies a NIP-98 signature on.
 *
 * The ground truth both credential tests below measure against, read off the
 * resolved middleware of the real routes. Deliberately not shared with
 * `MembershipApiDocumentTransformer::requiresSignature()`: a test that called
 * the very method it is checking would assert that the code agrees with
 * itself.
 *
 * @return array<string, bool>
 */
function signedApiV1Routes(): array
{
    $router = app(Router::class);
    $signed = [];

    foreach ($router->getRoutes() as $route) {
        $resolved = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

        // Same shape as the transformer's own check, deliberately: a looser
        // one here (`str_starts_with` without the colon) would also match a
        // future `VerifyNip98Strict`, and the test would then disagree with
        // the code for a reason that has nothing to do with the document.
        $verifies = (bool) array_filter(
            $resolved,
            fn (mixed $middleware): bool => is_string($middleware)
                && ($middleware === VerifyNip98::class || str_starts_with($middleware, VerifyNip98::class.':')),
        );

        foreach ($route->methods() as $method) {
            $signed[strtolower($method).' /'.$route->uri()] = $verifies;
        }
    }

    return $signed;
}

it('documents the credentials each endpoint actually verifies', function () {
    /*
     * MEASURED AGAINST THE ROUTES, NOT AGAINST A LIST, and that is the whole
     * value of this test.
     *
     * It used to be two tests over a hardcoded split — the configuration
     * endpoint needs the client key, "every other endpoint" needs both — and
     * the app branch (P8) sailed through both of them green while the
     * published document told a third party to sign three requests that have
     * no signature check behind them at all. A list cannot catch a new branch;
     * it IS the thing that goes stale. Reading `VerifyNip98` off the route
     * makes the assertion say what it means: the document must describe the
     * credential the server verifies.
     */
    $document = exportedOpenApiDocument();
    $signedRoutes = signedApiV1Routes();

    foreach (documentedApiV1Operations() as [$method, $path]) {
        $isSigned = $signedRoutes["{$method} {$path}"]
            ?? throw new RuntimeException("The document covers {$method} {$path}, which matches no route.");

        expect($document['paths'][$path][$method]['security'])->toBe([$isSigned
            ? [
                MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME => [],
                MembershipApiDocumentTransformer::NIP98_SCHEME => [],
            ]
            : [MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME => []],
        ], $isSigned
            ? "{$method} {$path} verifies a NIP-98 signature, so the document must ask for one."
            : "{$method} {$path} verifies NO signature, so the document must not ask for one.");
    }
});

it('never describes a NIP-98 failure on an endpoint that verifies no signature', function () {
    /*
     * The second half of the same defect, and `security` alone does not catch
     * it: the app-branch operations carried a 401 blaming "the NIP-98
     * credential" and a 503 about "the replay lock behind the NIP-98
     * verification" — two mechanisms that do not exist on that surface. A
     * client developer debugging a 401 there would have looked for a signature
     * bug forever.
     *
     * Which endpoints those are is read from the ROUTES, not from the
     * document's own `security` block. Deriving it from the document would
     * make this test assert nothing but the document's internal consistency —
     * it would go green on a document that is wrong in both halves at once,
     * which is precisely the state this change found.
     */
    $document = exportedOpenApiDocument();
    $signedRoutes = signedApiV1Routes();

    foreach (documentedApiV1Operations() as [$method, $path]) {
        $operation = $document['paths'][$path][$method];

        $isSigned = $signedRoutes["{$method} {$path}"]
            ?? throw new RuntimeException("The document covers {$method} {$path}, which matches no route.");

        if ($isSigned) {
            continue;
        }

        foreach ($operation['responses'] as $code => $response) {
            expect(str_contains($response['description'] ?? '', 'NIP-98'))
                ->toBeFalse("{$method} {$path} answers {$code} with prose about a NIP-98 check it does not perform.");
        }
    }
});

it('publishes the app branch as a client-key-only surface with a pubkey in the body', function () {
    /*
     * The three things a client of the app branch has to be told, and none of
     * them is inferable from the code Scramble reads: that the subject is a
     * body field, that the field is mandatory, and that a first application
     * answers 201 rather than 200 (Scramble sees `->setStatusCode()` and gives
     * up, documenting only 200 — a client treating 201 as a failure would
     * never complete a first join).
     */
    $document = exportedOpenApiDocument();

    foreach (['StoreAppApplicationRequest', 'StoreAppInvoiceRequest'] as $schema) {
        $body = $document['components']['schemas'][$schema];

        // `toContain` is variadic — a second argument would be a second value
        // to look for, not a failure message.
        expect($body['required'] ?? [])->toContain('pubkey')
            ->and($body['properties']['pubkey']['pattern'] ?? null)
            ->toBe('^[0-9a-f]{64}$', "{$schema}.pubkey must be documented as a required, canonical pubkey.");
    }

    expect(array_keys($document['paths']['/api/v1/app/membership/applications']['post']['responses']))
        ->toContain(201);

    // And no read surface, which is the price the branch pays for having no
    // signature. A `/me`, `/payments` or `/export` under `app/` would be an
    // oracle for foreign pubkeys.
    foreach (array_keys($document['paths']) as $path) {
        if (! str_starts_with($path, '/api/v1/app/')) {
            continue;
        }

        expect($path)->toBeIn([
            '/api/v1/app/membership/config',
            '/api/v1/app/membership/applications',
            '/api/v1/app/membership/payments/{year}/invoice',
        ], "{$path} is a fourth endpoint on the unsigned surface. See routes/api.php for why there are three.");
    }
});

it('tells a client where to ask for the two things it cannot obtain itself', function () {
    /*
     * A client key and an allowlisted `return_url` are both issued by the
     * association, and neither is reachable through this API. Without an
     * address in the document a developer meets them as a 401 and a 422 that
     * look like bugs in their own code — the one failure mode reading the
     * documentation harder cannot fix.
     *
     * Pinned in three places because a reader arrives at three different ones:
     * the introduction, the client-key scheme, and the `return_url` field of
     * each branch, which is where the question actually comes up.
     */
    $document = exportedOpenApiDocument();
    $contact = 'group.einundzwanzig.space';

    expect($document['info']['description'])->toContain($contact)
        ->and($document['components']['securitySchemes'][MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME]['description'])
        ->toContain($contact);

    foreach (['StoreInvoiceRequest', 'StoreAppInvoiceRequest'] as $schema) {
        // `toContain` is variadic, so the message goes on an expectation that
        // takes one.
        expect(str_contains($document['components']['schemas'][$schema]['properties']['return_url']['description'], $contact))
            ->toBeTrue("{$schema}.return_url must say where to have an address allowlisted.");
    }
});

it('warns against reading association_status on its own', function () {
    $document = exportedOpenApiDocument();

    // In the introduction a reader meets first...
    expect($document['info']['description'])->toContain('DO NOT EVALUATE `association_status` ON ITS OWN')
        ->and($document['info']['description'])->toContain('membership_status');

    // ...and on the field itself, which is where a schema browser lands.
    $field = $document['components']['schemas']['MembershipResource']['properties']['association_status'];

    expect($field['description'])->toContain('DO NOT EVALUATE `association_status` ON ITS OWN')
        ->and($field['description'])->toContain('Art. 4.1')
        ->and($field['description'])->toContain('membership_status');
});

it('publishes an npub that is the bech32 form of the pubkey next to it', function () {
    $subject = exportedOpenApiDocument()['components']['schemas']['MembershipExportResource']['properties']['subject']['properties'];

    $pubkey = $subject['pubkey']['examples'][0];
    $npub = $subject['npub']['examples'][0];

    /*
     * Computed, not compared against a second hardcoded constant. Two examples
     * maintained side by side is exactly how the published npub came to be an
     * invalid one: it was outside the bech32 alphabet, and
     * `Key::convertToHex()` threw `Invalid bech32 checksum` on it — a client
     * copying it out of the documentation could not have decoded it. Deriving
     * one from the other means the pair cannot drift again.
     */
    expect((new Key)->convertPublicKeyToBech32($pubkey))->toBe($npub);

    // And back, so a broken pubkey example cannot make a broken pair agree.
    expect((new Key)->convertToHex($npub))->toBe($pubkey);
});

it('publishes only canonical pubkey examples', function () {
    $document = exportedOpenApiDocument();

    foreach ([
        ['components', 'schemas', 'MembershipResource', 'properties', 'pubkey'],
        ['components', 'schemas', 'MembershipExportResource', 'properties', 'subject', 'properties', 'pubkey'],
    ] as $path) {
        $field = data_get($document, implode('.', $path));

        // NIP-01 knows one spelling, and the API rejects every other — an
        // example a client would be refused for copying is worse than none.
        expect($field['examples'][0])->toBeNostrHexKey();
    }
});

it('keeps internal prose out of every description in the document', function () {
    $prose = openApiProseFields(exportedOpenApiDocument());

    // A guard that walked an empty list would pass forever.
    expect(count($prose))->toBeGreaterThan(100);

    /*
     * WHY THIS IS GENERIC RATHER THAN FIELD BY FIELD. Scramble turns any
     * comment above an array entry in a JsonResource or a rule in a FormRequest
     * into a public field description. The fail-loud guard in
     * MembershipApiDocumentTransformer catches a new OPERATION that nobody
     * documented; it cannot catch a new FIELD, whose comment simply travels
     * out of the PHP file into this document unannounced. That has already
     * happened once here: the `nip05_handle` rule carried a security note
     * complete with a `<script>alert(1)</script>` payload and a reference to
     * the unversioned legacy endpoint, and it was published.
     */
    foreach ($prose as [$path, $text]) {
        expect($text)->not->toMatch('/(?i)\bplan step\b/', "Internal plan reference in {$path}.")
            ->and($text)->not->toMatch('/(?i)\baudit item\b/', "Internal audit reference in {$path}.")
            ->and($text)->not->toMatch('/(?i)\b(TODO|FIXME|XXX)\b/', "Unfinished note in {$path}.")
            ->and($text)->not->toMatch('#(?<![\w./])(app|config|resources|tests|database|routes|bootstrap)/#', "Repository path in {$path}.")
            ->and($text)->not->toMatch('/(?i)javascript:/', "Script URL in {$path}.")
            ->and($text)->not->toMatch('/(?i)\son[a-z]+\s*=/', "Inline event handler in {$path}.");
    }
});

it('contains no markup a documentation renderer would execute', function () {
    $tokens = [];

    foreach (openApiProseFields(exportedOpenApiDocument()) as [$path, $text]) {
        preg_match_all('#</?[^>\s][^>]*>#', $text, $matches);

        foreach ($matches[0] as $token) {
            $tokens[$token] = $path;
        }
    }

    /*
     * An allowlist of literal strings rather than a denylist of tag names.
     * A denylist has to anticipate the tag; this cannot be got past, because
     * ANY new angle-bracket token — `<script>`, `</script>`,
     * `<img src=x onerror=1>`, or simply a placeholder somebody added in good
     * faith — fails the test and gets looked at by a person.
     *
     * The three below are the placeholder notation of the authentication
     * recipes, and Scalar renders descriptions as Markdown, which passes raw
     * HTML through.
     */
    expect(array_keys($tokens))->toEqualCanonicalizing([
        '<your client key>',
        '<base64 of the event JSON>',
        '<handle>',
    ]);
});

it('rate-limits the documentation routes', function () {
    /*
     * Public, but not an unauthenticated CPU amplifier: Scramble rebuilds the
     * whole document on every request. Measured against a running server,
     * 0.29-0.39 s per call against 0.013-0.018 s for /up. The `web` group
     * brings no limit of its own, so the routes carry one explicitly.
     */
    foreach ([ApiDocumentationServiceProvider::UI_PATH, ApiDocumentationServiceProvider::DOCUMENT_PATH] as $path) {
        $response = $this->get('/'.$path);

        $response->assertOk();

        expect($response->headers->get('X-RateLimit-Limit'))->toBe('30', "{$path} is not rate-limited.");
    }
});

it('states the membership_status values a client has to handle', function () {
    $field = exportedOpenApiDocument()['components']['schemas']['MembershipResource']['properties']['membership_status'];

    expect($field['enum'])->toEqualCanonicalizing(['none', 'awaiting_payment', 'member', 'lapsed']);
});
