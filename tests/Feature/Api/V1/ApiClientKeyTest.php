<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
 * The client key answers "which application is calling", never "for whom".
 * These tests pin the three properties that make it worth having: it is
 * checked first, it resolves to a NAME, and the key itself never leaves the
 * middleware.
 */

const GROUP_KEY = '11111111111111111111111111111111111111111111111111111111aaaaaaaa';

const PARTNER_KEY = '22222222222222222222222222222222222222222222222222222222bbbbbbbb';

beforeEach(function () {
    registerApiV1TestRoutes();

    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => GROUP_KEY,
        'weiterer-client' => PARTNER_KEY,
    ]]);
});

/**
 * @param  array<string, string>  $headers
 */
function pingWithHeaders(array $headers): TestResponse
{
    return test()->withHeaders($headers + ['Accept' => 'application/json'])->get('/api/v1/_ping');
}

it('resolves a valid key to the name it belongs to', function (string $key, string $name) {
    $signed = makeNip98Event(apiV1PingUrl());

    pingWithHeaders([
        'X-Api-Key' => $key,
        'Authorization' => nip98Header($signed['event']),
    ])->assertSuccessful()->assertJsonPath('client', $name);
})->with([
    'first entry' => [GROUP_KEY, 'einundzwanzig-group'],
    'second entry' => [PARTNER_KEY, 'weiterer-client'],
]);

it('rejects a missing X-Api-Key header', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    pingWithHeaders(['Authorization' => nip98Header($signed['event'])])
        ->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('rejects an unknown key', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    pingWithHeaders([
        'X-Api-Key' => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        'Authorization' => nip98Header($signed['event']),
    ])->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
});

it('checks the client key before spending any NIP-98 verification or data write', function () {
    $signed = makeNip98Event(apiV1PingUrl());

    pingWithHeaders([
        'X-Api-Key' => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        'Authorization' => nip98Header($signed['event']),
    ])->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);

    // The decisive part: the SAME event still works. Had VerifyNip98 run
    // first, its replay lock would have burned the event id and this second
    // request would be a 401 as well — the assertion would pass for the wrong
    // reason. It succeeding proves the request was turned away before the
    // NIP-98 layer, and before the write in the route handler.
    pingWithHeaders([
        'X-Api-Key' => GROUP_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->assertSuccessful();

    expect(EinundzwanzigPleb::count())->toBe(1);
});

it('rejects everyone when no client keys are configured', function (mixed $configured) {
    config(['einundzwanzig.config.api_client_keys' => $configured]);

    $signed = makeNip98Event(apiV1PingUrl());

    pingWithHeaders([
        'X-Api-Key' => GROUP_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->assertUnauthorized();

    expect(EinundzwanzigPleb::count())->toBe(0);
})->with([
    'empty map' => [[]],
    'config key absent' => [null],
    'unparsable value' => ['einundzwanzig-group:secret'],
]);

it('never puts the key into a log line or an error response', function () {
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    $signed = makeNip98Event(apiV1PingUrl());

    $rejected = pingWithHeaders([
        'X-Api-Key' => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        'Authorization' => nip98Header($signed['event']),
    ])->assertUnauthorized();

    $accepted = pingWithHeaders([
        'X-Api-Key' => GROUP_KEY,
        'Authorization' => nip98Header($signed['event']),
    ])->assertSuccessful();

    // The rejection is logged — attribution is the point of the key — but the
    // secret is not what gets attributed.
    expect($logged)->not->toBeEmpty();

    $haystack = implode("\n", $logged)."\n".$rejected->getContent()."\n".$accepted->getContent();

    expect($haystack)
        ->not->toContain(GROUP_KEY)
        ->not->toContain(PARTNER_KEY)
        ->not->toContain('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff')
        ->toContain('api.v1 client key rejected');
});
