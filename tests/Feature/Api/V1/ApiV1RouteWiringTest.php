<?php

use App\Http\Middleware\ThrottleApiV1;
use App\Http\Middleware\VerifyApiClient;
use App\Http\Middleware\VerifyNip98;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
 * P3 ships no endpoints — P4 does. The middleware group is therefore tested
 * against placeholder routes, and this file is what makes that legitimate: it
 * proves the placeholder runs the REAL chain in the REAL order, so nothing
 * below is measuring a hand-assembled imitation.
 */

const WIRING_CLIENT_KEY = '44444444444444444444444444444444444444444444444444444444dddddddd';

beforeEach(function () {
    registerApiV1TestRoutes();

    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => WIRING_CLIENT_KEY,
    ]]);
});

it('runs the client check, then NIP-98, then the throttle — in that order', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->firstOrFail(fn ($route) => $route->getName() === 'api.v1.test.ping');

    $resolved = app('router')->gatherRouteMiddleware($route);

    $apiThrottle = array_search(ThrottleRequests::class.':api', $resolved, true);
    $client = array_search(VerifyApiClient::class, $resolved, true);
    $nip98 = array_search(VerifyNip98::class, $resolved, true);
    $v1Throttle = array_search(ThrottleApiV1::class.':api-v1', $resolved, true);

    expect([$apiThrottle, $client, $nip98, $v1Throttle])->not->toContain(false);

    // Laravel re-sorts route middleware by Kernel::$middlewarePriority before
    // running it. This assertion is the tripwire for that: written as
    // `throttle:api-v1`, the api-v1 quota was measurably pulled to the front
    // of the chain, ahead of both identity checks. See ThrottleApiV1.
    expect($apiThrottle)->toBeLessThan($client)
        ->and($client)->toBeLessThan($nip98)
        ->and($nip98)->toBeLessThan($v1Throttle);
});

it('runs inside the framework api group, not just the api.v1 group', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    $response = test()->withHeaders([
        'X-Api-Key' => WIRING_CLIENT_KEY,
        'Authorization' => nip98Header($signed['event']),
        'Accept' => 'application/json',
    ])->get('/api/v1/_ping');

    // The header carries the narrowest limit that ran, i.e. the api-v1 one
    // (30/min per pubkey) — the innermost throttle writes last. That the
    // outer throttle:api ran as well is what the order assertion above pins;
    // asserting 60 here would only prove which middleware wrote the header.
    $response->assertSuccessful()->assertHeader('X-RateLimit-Limit', 30);
});

it('refuses an unauthenticated request to the placeholder route', function () {
    // The other half of the same proof: the api.v1 group is applied too.
    test()->getJson('/api/v1/_ping')->assertUnauthorized();
});
