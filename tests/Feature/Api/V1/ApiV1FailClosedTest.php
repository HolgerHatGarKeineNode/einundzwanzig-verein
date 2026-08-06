<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/*
 * Direction of every failure path.
 *
 * No attacker is needed for any of this — a cache node rebooting, an env
 * variable lost in a deploy, a typo in a store name. The question each test
 * answers is the same: when a source this layer depends on cannot be read,
 * does the mechanism close or open? Anything that opens is worse than useless,
 * because it looks like protection while providing none.
 */

const FAIL_CLOSED_CLIENT_KEY = '33333333333333333333333333333333333333333333333333333333cccccccc';

/**
 * A cache store where every operation fails, standing in for an unreachable
 * cache node.
 */
final class ExplodingCacheStore implements Store
{
    public function get($key): mixed
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function increment($key, $value = 1): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function decrement($key, $value = 1): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function flush(): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function touch($key, $ttl): bool
    {
        throw new RuntimeException('cache store unreachable');
    }

    public function getPrefix(): string
    {
        return '';
    }
}

beforeEach(function () {
    registerApiV1TestRoutes();

    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => FAIL_CLOSED_CLIENT_KEY,
    ]]);
});

/**
 * @param  array<string, mixed>  $event
 */
function failClosedPing(array $event): TestResponse
{
    return test()->withHeaders([
        'X-Api-Key' => FAIL_CLOSED_CLIENT_KEY,
        'Authorization' => nip98Header($event),
        'Accept' => 'application/json',
    ])->get('/api/v1/_ping');
}

it('closes when the replay store cannot be reached', function () {
    Cache::extend('exploding', fn () => Cache::repository(new ExplodingCacheStore));
    config([
        'cache.stores.exploding' => ['driver' => 'exploding'],
        'einundzwanzig.config.api_replay_cache_store' => 'exploding',
    ]);

    $signed = makeNip98Event(apiV1PingUrl());

    // 503, not 200: without a working lock there is no replay defence at all,
    // and an unverifiable request must not be served.
    failClosedPing($signed['event'])->assertServiceUnavailable();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('closes when the replay store is misconfigured', function () {
    config(['einundzwanzig.config.api_replay_cache_store' => 'a-store-that-does-not-exist']);

    $signed = makeNip98Event(apiV1PingUrl());

    failClosedPing($signed['event'])->assertServiceUnavailable();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('closes when the client key configuration is unreadable', function (mixed $configured) {
    config(['einundzwanzig.config.api_client_keys' => $configured]);

    $signed = makeNip98Event(apiV1PingUrl());

    failClosedPing($signed['event'])->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
})->with([
    'missing' => [null],
    'empty' => [[]],
    'wrong type' => ['einundzwanzig-group:somekey'],
    'entries of the wrong type' => [['einundzwanzig-group' => ['nested']]],
]);

it('closes when the whole einundzwanzig config is unreadable', function () {
    // The most brutal variant: the config namespace is gone entirely. Nothing
    // in the chain may interpret "I cannot read my configuration" as "carry on".
    config(['einundzwanzig' => null]);

    $signed = makeNip98Event(apiV1PingUrl());

    failClosedPing($signed['event'])->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('treats an empty replay store name as "use the default store"', function () {
    /*
     * `API_REPLAY_CACHE_STORE=` — the exact line shipped in .env.example —
     * yields the empty STRING from dotenv, not null, and
     * CacheManager::store() falls back to the default store only for null.
     * Measured against `php -S` with that line in place: every authenticated
     * /api/v1 request answered 503. The direction was right and the state was
     * still a total outage, and no test could see it, because phpunit.xml
     * never sets the variable and env() then returns null.
     */
    config(['einundzwanzig.config.api_replay_cache_store' => '']);

    $signed = makeNip98Event(apiV1PingUrl());

    failClosedPing($signed['event'])->assertSuccessful();

    // Still a real lock, not merely "no longer 503".
    failClosedPing($signed['event'])->assertUnauthorized();
});

it('serves the request when every source is intact', function () {
    // The control case. Without it the four tests above would also pass on a
    // layer that rejects everything unconditionally.
    $signed = makeNip98Event(apiV1PingUrl());

    failClosedPing($signed['event'])->assertSuccessful();

    expect(EinundzwanzigPleb::count())->toBe(1);
});
