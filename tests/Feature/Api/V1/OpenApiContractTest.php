<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
 * Two guarantees `OpenApiDocumentationTest.php` does not give, because both
 * need a second source of truth next to the committed file.
 *
 * COVERAGE, IN BOTH DIRECTIONS. `documents exactly the eight /api/v1
 * endpoints` in the sibling file pins the document against a hardcoded list
 * of eight operations — it catches the document drifting from THAT LIST, not
 * the list (or the document) drifting from `routes/api.php`. A new route
 * added to the group and never exported would leave that test, and every
 * other test in this repo, green. The tests below compare the document
 * against `Route::getRoutes()` instead: a real route with no matching spec
 * entry fails, and a spec entry with no matching real route fails. The
 * second direction is the one that matters day to day — it is the one that
 * catches "shipped an endpoint, forgot the doc".
 *
 * FRESHNESS. Every hygiene assertion in the sibling file, and both directions
 * of the contract test above, read `docs/openapi/v1.json` — the checked-in
 * file, not the code. All of them would stay green forever if someone edited
 * a controller and never re-ran `scramble:export`. The last test here closes
 * that gap: it exports fresh, into a file outside the repository, and
 * compares the two documents structurally.
 */

beforeEach(function () {
    /*
     * `scramble:export` (vendor/dedoc/scramble/src/Console/Commands/
     * ExportDocumentation.php) type-hints the plain `Generator`, not the
     * `CacheableGenerator` the documentation ROUTES resolve
     * (ScrambleServiceProvider::registerApi), so the fresh export below does
     * not currently read the file cache no matter what this is set to.
     * Disabled anyway, for the same reason the sibling file disables it: a
     * future Scramble version routing the export through the cache should
     * fail a test, not fail silently, and this suite should measure the code
     * in the working tree rather than whatever `scramble:cache` last wrote.
     */
    config(['scramble.cache.store' => null]);
});

/**
 * The decoded, committed document.
 *
 * Deliberately not `exportedOpenApiDocument()` from OpenApiDocumentationTest.php:
 * Pest requires every *Test.php file into one PHP process for a full suite
 * run (no paratest here — see composer.json `test`), so a second top-level
 * function of that name would be a fatal "Cannot redeclare function", not a
 * failing test.
 *
 * @return array<string, mixed>
 */
function contractTestCommittedDocument(): array
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
 * Every [method, path] operation the document claims to cover, spelled
 * exactly as it appears there (leading slash, `{param}` placeholders,
 * lowercase HTTP method — Scramble's own casing).
 *
 * @param  array<string, mixed>  $document
 * @return list<array{0: string, 1: string}>
 */
function contractTestSpecOperations(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $operations[] = [$method, $path];
        }
    }

    return $operations;
}

/**
 * Every [method, path] operation a REAL route answers to under `api/v1/`,
 * normalized to the shape the spec uses.
 *
 * Filtered on the literal prefix `api/v1/` rather than on a denylist of the
 * four routes that must NOT appear here — the two legacy endpoints
 * (`GET /api/members/{year}`, `GET /api/nostr/profile/{key}`) and the two
 * documentation routes (`docs/v1/api`, `docs/v1/api.json`). None of those
 * four URIs start with `api/v1/`, so all four fall out on their own; a fifth
 * route added under `api/v1/*` tomorrow is caught by the SAME condition that
 * already governs what Scramble documents (config/scramble.php,
 * `api_path => ['include' => 'api/v1/*']`), not by a second copy of that
 * decision that could quietly drift from the first and wave a real endpoint
 * through. An exclusion list would have exactly that failure mode: add a
 * ninth `/api/v1/...` route and forget to touch the list, and the "every
 * real route is documented" direction below would never see it.
 *
 * Two normalizations happen here, and neither is cosmetic — both were
 * measured against `php artisan route:list --path=api --json` before being
 * written, not assumed:
 *   - Laravel's route URIs carry no leading slash (`api/v1/membership/me`);
 *     the spec's do (`/api/v1/membership/me`). One is added back.
 *   - Every GET route also answers HEAD — added by Laravel itself when the
 *     route is registered, never a method Scramble emits as its own
 *     operation. Left in, the "every real route is documented" direction
 *     would fail on every single GET endpoint in the group.
 *
 * @return list<array{0: string, 1: string}>
 */
function contractTestRealOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $operations[] = [strtolower($method), '/'.$uri];
        }
    }

    return $operations;
}

/**
 * A minimal structural diff between two decoded JSON documents, keyed by
 * JSON-pointer-ish path — built so a failing drift test can say WHERE the
 * two documents disagree instead of just that they do. `assertEquals` on the
 * two 100+ KB arrays would print exactly that: a byte diff nobody reads at
 * 23:00.
 *
 * @return list<string>
 */
function contractTestDocumentDiff(mixed $expected, mixed $actual, string $pointer = '$'): array
{
    if ($expected === $actual) {
        return [];
    }

    if (! is_array($expected) || ! is_array($actual)) {
        return ["{$pointer}: docs/openapi/v1.json has ".json_encode($expected).', fresh export has '.json_encode($actual)];
    }

    $differences = [];

    foreach (array_keys($expected) as $key) {
        $childPointer = is_int($key) ? "{$pointer}[{$key}]" : "{$pointer}.{$key}";

        if (! array_key_exists($key, $actual)) {
            $differences[] = "{$childPointer}: in docs/openapi/v1.json, missing from the fresh export.";

            continue;
        }

        $differences = [...$differences, ...contractTestDocumentDiff($expected[$key], $actual[$key], $childPointer)];
    }

    foreach (array_keys($actual) as $key) {
        if (! array_key_exists($key, $expected)) {
            $childPointer = is_int($key) ? "{$pointer}[{$key}]" : "{$pointer}.{$key}";
            $differences[] = "{$childPointer}: in the fresh export, missing from docs/openapi/v1.json.";
        }
    }

    return $differences;
}

it('documents every real /api/v1 route, and no route that is not real', function () {
    $spec = contractTestSpecOperations(contractTestCommittedDocument());
    $real = contractTestRealOperations();

    $undocumented = array_values(array_filter(
        $real,
        fn (array $operation): bool => ! in_array($operation, $spec, true)
    ));

    $phantom = array_values(array_filter(
        $spec,
        fn (array $operation): bool => ! in_array($operation, $real, true)
    ));

    expect($undocumented)->toBe([], 'Real route(s) with no entry in docs/openapi/v1.json (method, path): '
        .json_encode($undocumented)
        .'. Run: php artisan scramble:export --path=docs/openapi/v1.json --api=v1')
        ->and($phantom)->toBe([], 'docs/openapi/v1.json documents route(s) that do not exist in routes/api.php (method, path): '
            .json_encode($phantom));
});

it('matches a fresh export of the code that produced it', function () {
    // Outside the repository on purpose — a test that writes into the
    // working tree while proving the working tree is unchanged would defeat
    // its own point. Cleaned up in `finally` regardless of outcome.
    $freshPath = tempnam(sys_get_temp_dir(), 'openapi-v1-drift-');

    try {
        Artisan::call('scramble:export', [
            '--path' => $freshPath,
            '--api' => 'v1',
        ]);

        $fresh = json_decode((string) file_get_contents($freshPath), true);

        expect($fresh)->toBeArray('A fresh `scramble:export` did not produce valid JSON.');

        $differences = contractTestDocumentDiff(contractTestCommittedDocument(), $fresh);

        expect($differences)->toBe([], implode("\n", [
            'docs/openapi/v1.json is stale — it no longer matches what the code exports.',
            'Run: php artisan scramble:export --path=docs/openapi/v1.json --api=v1',
            '',
            'Where it differs:',
            ...array_slice($differences, 0, 20),
            ...(count($differences) > 20 ? ['... and '.(count($differences) - 20).' more.'] : []),
        ]));
    } finally {
        if (file_exists($freshPath)) {
            unlink($freshPath);
        }
    }
});
