<?php

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;

/**
 * Plan step 37 asked for "rate limit store failure → 503 instead of letting the
 * request through", on the premise that Laravel's ThrottleRequests lets traffic
 * through when the cache is down.
 *
 * THAT PREMISE DOES NOT HOLD ON THIS STACK, and this test is the measurement
 * rather than the fix. `Illuminate\Routing\Middleware\ThrottleRequests`
 * (laravel/framework 13.23.0) contains no catch of any kind: a store that
 * throws propagates, the request is refused, and nothing reaches the endpoint.
 * There is no pass-through to close.
 *
 * What is left is a difference in status code — the refusal surfaces as a 500,
 * not a 503 — and that is deliberately NOT changed here. The api group's outer
 * `throttle:api` runs first (bootstrap/app.php), so any conversion applied to
 * the inner api.v1 throttle would never be reached; building it would mean
 * shipping code that cannot run. Rewiring the outer one instead means touching
 * the P3 auth chain, which this phase is explicitly not to rebuild.
 *
 * So the guarantee is pinned here as a tripwire: whatever the status code, a
 * broken rate limit store must never produce a SUCCESSFUL response. If a
 * future framework version adds a "fail open" fallback, this test fails and
 * the decision gets made deliberately rather than inherited silently.
 */
const RLS_CLIENT_KEY = 'rls11111111111111111111111111111111111111111111111111111rls11';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => RLS_CLIENT_KEY]]);
});

it('never lets a request through when the rate limit store fails', function () {
    Http::fake();

    $broken = Mockery::mock(Repository::class);
    $broken->shouldIgnoreMissing();
    $broken->shouldReceive('get')->andThrow(new RuntimeException('rate limit store is down'));
    $broken->shouldReceive('add')->andThrow(new RuntimeException('rate limit store is down'));
    $broken->shouldReceive('increment')->andThrow(new RuntimeException('rate limit store is down'));
    $broken->shouldReceive('put')->andThrow(new RuntimeException('rate limit store is down'));

    $limiter = new RateLimiter($broken);
    $limiter->for('api', fn () => Limit::perMinute(60)->by('probe'));
    $limiter->for('api-v1', fn () => Limit::perMinute(30)->by('probe'));

    app()->instance(RateLimiter::class, $limiter);

    $response = $this->withHeaders([
        'X-Api-Key' => RLS_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/config');

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getStatusCode())->toBeGreaterThanOrEqual(500);
});
