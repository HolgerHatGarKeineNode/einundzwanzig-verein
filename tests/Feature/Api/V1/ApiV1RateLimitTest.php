<?php

use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * Two independent buckets, deliberately not keyed by IP.
 *
 * The limits are read from config on every request, so the tests can lower
 * them instead of signing 120 Schnorr events per assertion — a run that would
 * take minutes and would still not test anything the low numbers do not.
 */

const RATE_LIMIT_CLIENT_KEY = '55555555555555555555555555555555555555555555555555555555eeeeeeee';

beforeEach(function () {
    registerApiV1TestRoutes();

    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => RATE_LIMIT_CLIENT_KEY,
    ]]);
});

function rateLimitedPing(?string $privkey = null, string $path = '/api/v1/_ping'): TestResponse
{
    $signed = makeNip98Event(url($path), 'POST', '[]', privkey: $privkey);

    return test()->withHeaders([
        'X-Api-Key' => RATE_LIMIT_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->postJson($path);
}

it('throttles a single pubkey', function () {
    config([
        'einundzwanzig.config.api_rate_limits.pubkey_per_minute' => 2,
        'einundzwanzig.config.api_rate_limits.client_per_minute' => 100,
    ]);

    $privkey = (new Key)->generatePrivateKey();

    rateLimitedPing($privkey)->assertSuccessful();
    rateLimitedPing($privkey)->assertSuccessful();
    rateLimitedPing($privkey)->assertStatus(429);
});

it('throttles a client name across different pubkeys', function () {
    config([
        'einundzwanzig.config.api_rate_limits.client_per_minute' => 2,
        'einundzwanzig.config.api_rate_limits.pubkey_per_minute' => 100,
    ]);

    // Three different end users, one calling application. Each pubkey bucket
    // sees a single request, so the 429 can only come from the client bucket —
    // which is the point of counting per application at all.
    rateLimitedPing()->assertSuccessful();
    rateLimitedPing()->assertSuccessful();
    rateLimitedPing()->assertStatus(429);
});

it('does not let one client exhaust another client quota', function () {
    config([
        'einundzwanzig.config.api_rate_limits.client_per_minute' => 1,
        'einundzwanzig.config.api_rate_limits.pubkey_per_minute' => 100,
        'einundzwanzig.config.api_client_keys' => [
            'einundzwanzig-group' => RATE_LIMIT_CLIENT_KEY,
            'weiterer-client' => '66666666666666666666666666666666666666666666666666666666ffffffff',
        ],
    ]);

    rateLimitedPing()->assertSuccessful();
    rateLimitedPing()->assertStatus(429);

    $signed = makeNip98Event(apiV1PingUrl(), 'POST', '[]');

    test()->withHeaders([
        'X-Api-Key' => '66666666666666666666666666666666666666666666666666666666ffffffff',
        'Authorization' => nip98Header($signed['event']),
    ])->postJson('/api/v1/_ping')->assertSuccessful();
});

it('throttles invoice creation per pubkey and day', function () {
    config(['einundzwanzig.config.api_rate_limits.invoice_per_day' => 1]);

    $privkey = (new Key)->generatePrivateKey();

    rateLimitedPing($privkey, '/api/v1/_ping-invoice')->assertSuccessful();
    rateLimitedPing($privkey, '/api/v1/_ping-invoice')->assertStatus(429);

    // A different member is unaffected — the invoice budget is personal, not
    // a global valve that the first caller of the day can close for everyone.
    rateLimitedPing(null, '/api/v1/_ping-invoice')->assertSuccessful();
});
