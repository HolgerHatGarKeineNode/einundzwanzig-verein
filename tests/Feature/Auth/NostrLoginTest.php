<?php

use App\Support\NostrAuth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Key\Key as NostrKey;
use swentel\nostr\Sign\Sign as NostrSign;

/**
 * Build a NIP-42-style kind-22242 login event signed with a freshly generated
 * keypair. Returns the signed event as the plain array that the frontend
 * dispatches to Livewire (post-JSON round-trip), plus the pubkey for assertions.
 *
 * @return array{event: array<string, mixed>, pubkey: string, privkey: string}
 */
function makeSignedLoginEvent(string $challenge, ?int $createdAt = null): array
{
    $key = new NostrKey;
    $privkey = $key->generatePrivateKey();
    $pubkey = $key->getPublicKey($privkey);

    $event = new NostrEvent;
    $event->setKind(22242);
    $event->setCreatedAt($createdAt ?? time());
    $event->setTags([['challenge', $challenge]]);
    $event->setContent('');

    (new NostrSign)->signEvent($event, $privkey);

    $array = $event->toArray();

    // Match the shape produced by JSON.parse(JSON.stringify(signedEvent)) in
    // nostrLogin.js — plain arrays, integer kind/created_at, string sig/id.
    return [
        'event' => [
            'id' => $array['id'],
            'pubkey' => $array['pubkey'],
            'created_at' => $array['created_at'],
            'kind' => $array['kind'],
            'tags' => $array['tags'],
            'content' => $array['content'],
            'sig' => $array['sig'],
        ],
        'pubkey' => $pubkey,
        'privkey' => $privkey,
    ];
}

it('issues a fresh hex challenge and persists it to the session', function () {
    $challenge = NostrAuth::issueChallenge();

    expect($challenge)->toBeNostrHexKey();
    expect(Session::get('nostr_login_challenge'))->toBe($challenge);
    expect(Session::get('nostr_login_challenge_expires_at'))->toBeGreaterThan(now()->timestamp);
});

it('logs in via a valid signed login event and consumes the challenge', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    $returned = NostrAuth::loginWithSignedEvent($signedEvent);

    expect($returned)->toBe($pubkey);
    expect(NostrAuth::check())->toBeTrue();
    expect(NostrAuth::pubkey())->toBe($pubkey);
    expect(Session::has('nostr_login_challenge'))->toBeFalse();
});

it('rejects an event whose challenge does not match the session', function () {
    NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent('deadbeef'.str_repeat('0', 56));

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});

it('rejects an event of the wrong kind', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);
    $signedEvent['kind'] = 1; // text note, not auth

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});

it('rejects an event whose created_at is outside the TTL window', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge, now()->subHour()->timestamp);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});

it('rejects an event with a tampered signature', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);
    // Flip the first byte of the signature to break the schnorr verification.
    $signedEvent['sig'] = ($signedEvent['sig'][0] === '0' ? '1' : '0').substr($signedEvent['sig'], 1);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});

it('rejects an event with a tampered pubkey (sig no longer matches)', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);
    // Swap in an attacker-controlled pubkey while keeping the original sig.
    $signedEvent['pubkey'] = str_repeat('a', 64);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});

it('rejects a non-array payload', function () {
    NostrAuth::issueChallenge();

    expect(fn () => NostrAuth::loginWithSignedEvent('not-an-event'))
        ->toThrow(ValidationException::class);
    expect(fn () => NostrAuth::loginWithSignedEvent(null))
        ->toThrow(ValidationException::class);
});

it('is idempotent for repeated calls with the same event within one session', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($signedEvent);
    // Challenge is consumed after the first call. A sibling listener that
    // receives the same dispatched event must still succeed.
    $returned = NostrAuth::loginWithSignedEvent($signedEvent);

    expect($returned)->toBe($pubkey);
    expect(NostrAuth::pubkey())->toBe($pubkey);
});

it('does not allow a replay from a different (unauthenticated) session', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($signedEvent);

    // Simulate a fresh session: no challenge, no authenticated user.
    NostrAuth::logout();
    Session::forget(['nostr_login_challenge', 'nostr_login_challenge_expires_at']);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);
    expect(NostrAuth::check())->toBeFalse();
});
