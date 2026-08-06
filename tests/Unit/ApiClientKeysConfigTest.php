<?php

/*
 * The API_CLIENT_KEYS grammar, exercised against the real config file.
 *
 * Worth its own test because the parser is the fail-closed boundary: it turns
 * a free-form env string into the map VerifyApiClient trusts, and every way
 * that string can be malformed has to end in "fewer entries", never in "a
 * surprising entry".
 */

/**
 * @return array<string, string>
 */
function parseApiClientKeys(string $raw): array
{
    $previous = $_SERVER['API_CLIENT_KEYS'] ?? null;
    $_SERVER['API_CLIENT_KEYS'] = $raw;

    try {
        // Path resolved from this file, not base_path(): Unit tests do not
        // boot the application (see tests/Pest.php — only Feature does).
        $config = require dirname(__DIR__, 2).'/config/einundzwanzig/config.php';
    } finally {
        if ($previous === null) {
            unset($_SERVER['API_CLIENT_KEYS']);
        } else {
            $_SERVER['API_CLIENT_KEYS'] = $previous;
        }
    }

    return $config['api_client_keys'];
}

/** A key of the shape `php artisan api:client-key` mints: 64 hex characters. */
const KEY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

const KEY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2';

it('parses a name-to-key map', function () {
    expect(parseApiClientKeys('einundzwanzig-group:'.KEY_A.',weiterer-client:'.KEY_B))
        ->toBe([
            'einundzwanzig-group' => KEY_A,
            'weiterer-client' => KEY_B,
        ]);
});

it('tolerates whitespace around entries', function () {
    expect(parseApiClientKeys(' einundzwanzig-group : '.KEY_A.' , weiterer-client:'.KEY_B.' '))
        ->toBe([
            'einundzwanzig-group' => KEY_A,
            'weiterer-client' => KEY_B,
        ]);
});

it('splits only on the first colon', function () {
    // Anything after the first colon belongs to the key. Splitting on every
    // colon would silently truncate a key and lock the client out with a 401
    // nobody could explain. The charset rule then rejects the result — which
    // is the point: a key that cannot be represented in this grammar must not
    // turn into a different, shorter, working key.
    expect(parseApiClientKeys('client:'.KEY_A.':tail'))->toBe([]);
});

it('drops malformed entries instead of guessing', function (string $raw) {
    expect(parseApiClientKeys($raw))->toBe([]);
})->with([
    'empty' => [''],
    'only whitespace' => ['   '],
    'no separator' => ['justakey'],
    'empty name' => [':'.KEY_A],
    'empty key' => ['client:'],
    'nothing but separators' => [',,,'],
    'key too short' => ['client:0123456789abcdef'],
    'key with illegal characters' => ['client:'.substr(KEY_A, 0, 60).'!!!!'],
]);

it('drops a key that a comma has silently truncated', function () {
    // "," separates entries, so a comma inside a key is not representable.
    // Before the charset rule the truncated remnant became a VALID, shorter
    // key: the operator would have configured one secret and deployed a
    // different one, with nothing anywhere saying so.
    expect(parseApiClientKeys('client:'.substr(KEY_A, 0, 30).','.substr(KEY_A, 30)))
        ->toBe([]);
});

it('keeps the first entry when a name is configured twice', function () {
    // Deterministic and conservative: a duplicated name must not silently
    // replace an already valid key.
    expect(parseApiClientKeys('client:'.KEY_A.',client:'.KEY_B))
        ->toBe(['client' => KEY_A]);
});

it('keeps the well-formed entries of a partially malformed value', function () {
    expect(parseApiClientKeys('broken,einundzwanzig-group:'.KEY_A.',:orphan'))
        ->toBe(['einundzwanzig-group' => KEY_A]);
});
