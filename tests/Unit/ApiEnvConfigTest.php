<?php

/*
 * Env values that mean "unset" but do not arrive as null.
 *
 * Dotenv hands back the EMPTY STRING for a bare `FOO=` line, and several
 * framework APIs distinguish the two. Both cases below are shipped that way
 * in .env.example, so getting this wrong breaks a fresh installation rather
 * than an exotic configuration.
 */

/**
 * Load a config file with one env variable set, then put the environment back.
 *
 * @return array<string, mixed>
 */
function loadConfigWithEnv(string $file, string $variable, ?string $value): array
{
    $previous = $_SERVER[$variable] ?? null;

    if ($value === null) {
        unset($_SERVER[$variable]);
    } else {
        $_SERVER[$variable] = $value;
    }

    try {
        // Path resolved from this file: Unit tests do not boot the
        // application (see tests/Pest.php — only Feature does).
        return require dirname(__DIR__, 2).'/config/'.$file;
    } finally {
        if ($previous === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $previous;
        }
    }
}

it('turns an empty replay store name into null', function () {
    // '' would reach CacheManager::store() and raise
    // "Cache store [] is not defined", answering 503 to every request.
    $config = loadConfigWithEnv('einundzwanzig/config.php', 'API_REPLAY_CACHE_STORE', '');

    expect($config['api_replay_cache_store'])->toBeNull();
});

it('keeps a configured replay store name', function () {
    $config = loadConfigWithEnv('einundzwanzig/config.php', 'API_REPLAY_CACHE_STORE', 'redis');

    expect($config['api_replay_cache_store'])->toBe('redis');
});

it('trusts no proxy when TRUSTED_PROXIES is empty', function () {
    // The empty ARRAY matters: TrustProxies calls setTrustedProxies([]) for
    // it, whereas null would reach the framework's built-in special case for
    // *.on-forge.com and Laravel Cloud, which switches to '*' by itself.
    expect(loadConfigWithEnv('trustedproxy.php', 'TRUSTED_PROXIES', '')['proxies'])->toBe([])
        ->and(loadConfigWithEnv('trustedproxy.php', 'TRUSTED_PROXIES', null)['proxies'])->toBe([]);
});

it('parses a proxy list', function () {
    expect(loadConfigWithEnv('trustedproxy.php', 'TRUSTED_PROXIES', '10.0.0.1, 192.168.0.0/16')['proxies'])
        ->toBe(['10.0.0.1', '192.168.0.0/16']);
});

it('passes a wildcard through as a string', function () {
    // TrustProxies compares '*' against the string, not against an array —
    // ['*'] would be treated as a single proxy whose address is "*".
    expect(loadConfigWithEnv('trustedproxy.php', 'TRUSTED_PROXIES', '*')['proxies'])->toBe('*');
});
