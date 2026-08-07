<?php

use App\Providers\ApiDocumentationServiceProvider;
use App\Support\OpenApi\MembershipApiDocumentTransformer;
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
 * silently is its perimeter: eight documented endpoints, both authentication
 * schemes, and the warning about `association_status` — the one field on this
 * API a consumer can get wrong without noticing.
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
 * The eight endpoints this API consists of, method and path exactly as the
 * document has to spell them.
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

it('documents exactly the eight /api/v1 endpoints', function () {
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
        expect($path)->toStartWith('/api/v1/membership');
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

it('requires only the client key for the configuration endpoint', function () {
    $operation = exportedOpenApiDocument()['paths']['/api/v1/membership/config']['get'];

    expect($operation['security'])->toBe([[
        MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME => [],
    ]]);
});

it('requires both schemes on every other endpoint', function () {
    $document = exportedOpenApiDocument();

    foreach (documentedApiV1Operations() as [$method, $path]) {
        if ($path === '/api/v1/membership/config') {
            continue;
        }

        expect($document['paths'][$path][$method]['security'])->toBe([[
            MembershipApiDocumentTransformer::CLIENT_KEY_SCHEME => [],
            MembershipApiDocumentTransformer::NIP98_SCHEME => [],
        ]], "{$method} {$path} must require the client key AND a NIP-98 signature.");
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
