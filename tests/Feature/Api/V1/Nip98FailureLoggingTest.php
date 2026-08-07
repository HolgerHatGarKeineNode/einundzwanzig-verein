<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use swentel\nostr\Key\Key;

/*
 * Before this, an attack on the signature check left no trace at all.
 * VerifyApiClient logged its own rejections, so a wrong client key was on
 * record — but forged signatures, replayed events and events signed for a
 * foreign origin were answered with a 401 that nobody could ever count.
 */

const LOG_CLIENT_KEY = 'log11111111111111111111111111111111111111111111111111111111log1';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => LOG_CLIENT_KEY,
    ]]);
});

/**
 * @return array<int, array{message: string, context: array<string, mixed>}>
 */
function captureLog(Closure $work): array
{
    $entries = [];

    Log::listen(function (MessageLogged $message) use (&$entries) {
        $entries[] = ['message' => $message->message, 'context' => $message->context];
    });

    $work();

    return $entries;
}

it('records a failed NIP-98 check with its reason and the calling client', function () {
    // kind 27236 instead of 27235. Nip98::verify() checks the kind before
    // anything else, so the reason below is provably the one under test and not
    // an earlier guard tripping first.
    $signed = makeNip98Event(url('/api/v1/membership/me'), 'GET', kind: 27236);

    $entries = captureLog(function () use ($signed) {
        test()->withHeaders([
            'X-Api-Key' => LOG_CLIENT_KEY,
            'Authorization' => $signed['header'],
            'Accept' => 'application/json',
        ])->get('/api/v1/membership/me')->assertUnauthorized();
    });

    $nip98 = collect($entries)->firstWhere('message', 'api.v1 nip-98 rejected');

    expect($nip98)->not->toBeNull()
        ->and($nip98['context']['reason'])->toBe('invalid_kind')
        // The resolved NAME of the application, never its key — without
        // attribution the entry says only "somebody failed".
        ->and($nip98['context']['client'])->toBe('einundzwanzig-group')
        ->and($nip98['context']['path'])->toBe('api/v1/membership/me')
        ->and($nip98['context']['method'])->toBe('GET')
        ->and($nip98['context'])->toHaveKey('ip');
});

it('records the distinct reason for each different failure', function (int $kind, ?int $ageSeconds, string $expected) {
    $signed = makeNip98Event(
        url('/api/v1/membership/me'),
        'GET',
        createdAt: $ageSeconds === null ? now()->timestamp : now()->timestamp - $ageSeconds,
        kind: $kind,
    );

    $entries = captureLog(function () use ($signed) {
        test()->withHeaders([
            'X-Api-Key' => LOG_CLIENT_KEY,
            'Authorization' => $signed['header'],
            'Accept' => 'application/json',
        ])->get('/api/v1/membership/me');
    });

    $nip98 = collect($entries)->firstWhere('message', 'api.v1 nip-98 rejected');

    expect($nip98)->not->toBeNull()
        ->and($nip98['context']['reason'])->toBe($expected);
})->with([
    // A single blanket "rejected" would be useless: telling a client that
    // misbuilds its events apart from somebody probing the signature check is
    // the reason to keep the log at all.
    'wrong kind' => [27236, null, 'invalid_kind'],
    'stale event' => [27235, 600, 'stale_event'],
]);

it('records a missing Authorization header', function () {
    $entries = captureLog(function () {
        test()->withHeaders([
            'X-Api-Key' => LOG_CLIENT_KEY,
            'Accept' => 'application/json',
        ])->get('/api/v1/membership/me')->assertUnauthorized();
    });

    expect(collect($entries)->firstWhere('message', 'api.v1 nip-98 rejected'))
        ->not->toBeNull()
        ->and(collect($entries)->firstWhere('message', 'api.v1 nip-98 rejected')['context']['reason'])
        ->toBe('missing_authorization');
});

it('puts no personal data and no secret into the entry', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    /*
     * A real member with real data behind the pubkey being presented. Without
     * this record the assertions below would hold for the trivial reason that
     * there was nothing to leak in the first place.
     */
    EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
    ]);

    $signed = makeNip98Event(url('/api/v1/membership/me'), 'GET', privkey: $privkey, kind: 27236);

    $entries = captureLog(function () use ($signed) {
        test()->withHeaders([
            'X-Api-Key' => LOG_CLIENT_KEY,
            'Authorization' => $signed['header'],
            'Accept' => 'application/json',
        ])->get('/api/v1/membership/me')->assertUnauthorized();
    });

    $haystack = collect($entries)
        ->map(fn (array $entry): string => $entry['message'].' '.json_encode($entry['context']))
        ->implode("\n");

    expect($haystack)->toContain('api.v1 nip-98 rejected')
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose')
        // A secret that reaches the log has been shared with everyone who can
        // read the log.
        ->not->toContain(LOG_CLIENT_KEY)
        /*
         * And not the pubkey either. At the moment of failure the signature has
         * NOT been verified, so that value is whatever the sender wrote there —
         * recording it would file an attacker-chosen identity as established.
         */
        ->not->toContain($pubkey);
});

it('logs nothing when the signature is good', function () {
    $entries = captureLog(function () {
        apiV1SignedGet('/api/v1/membership/me', LOG_CLIENT_KEY)['response']->assertOk();
    });

    expect(collect($entries)->firstWhere('message', 'api.v1 nip-98 rejected'))->toBeNull();
});
