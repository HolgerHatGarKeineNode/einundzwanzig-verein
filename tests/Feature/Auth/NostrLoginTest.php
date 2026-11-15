<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use swentel\nostr\Key\Key as NostrKey;

/*
 * `makeSignedLoginEvent()` lives in tests/Pest.php — more than one suite needs
 * a genuinely signed login event.
 */

it('issues a fresh hex challenge and persists it to the session', function () {
    $challenge = NostrAuth::issueChallenge();

    expect($challenge)->toBeNostrHexKey()
        ->and(Session::get('nostr_login_challenge'))->toBe($challenge)
        ->and(Session::get('nostr_login_challenge_expires_at'))->toBeGreaterThan(now()->timestamp);
});

it('logs in via a valid signed login event and consumes the challenge', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    $returned = NostrAuth::loginWithSignedEvent($signedEvent);

    expect($returned)->toBe($pubkey)
        ->and(NostrAuth::check())->toBeTrue()
        ->and(NostrAuth::pubkey())->toBe($pubkey)
        ->and(Session::has('nostr_login_challenge'))->toBeFalse();
});

it('rejects an event whose challenge does not match the session', function () {
    NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent('deadbeef'.str_repeat('0', 56));

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
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
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
});

it('rejects an event with a tampered signature', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);
    // Flip the first byte of the signature to break the schnorr verification.
    $signedEvent['sig'] = ($signedEvent['sig'][0] === '0' ? '1' : '0').substr($signedEvent['sig'], 1);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
});

it('rejects an event with a tampered pubkey (sig no longer matches)', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);
    // Swap in an attacker-controlled pubkey while keeping the original sig.
    $signedEvent['pubkey'] = str_repeat('a', 64);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
});

it('rejects a non-array payload', function () {
    NostrAuth::issueChallenge();

    expect(fn () => NostrAuth::loginWithSignedEvent('not-an-event'))
        ->toThrow(ValidationException::class)
        ->and(fn () => NostrAuth::loginWithSignedEvent(null))->toThrow(ValidationException::class);
});

it('is idempotent for repeated calls with the same event within one session', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($signedEvent);
    // Challenge is consumed after the first call. A sibling listener that
    // receives the same dispatched event must still succeed.
    $returned = NostrAuth::loginWithSignedEvent($signedEvent);

    expect($returned)->toBe($pubkey)
        ->and(NostrAuth::pubkey())->toBe($pubkey);
});

/*
 * The member record is created on the authenticated path. It used to appear as
 * a side effect of the unauthenticated `GET /api/nostr/profile/{key}` that the
 * login frontend called on its way past — remove that write and nothing created
 * plebs any more, which silently broke every first-time login.
 */

it('creates the member record for a previously unknown pubkey on a valid login', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();

    NostrAuth::loginWithSignedEvent($signedEvent);

    $plebs = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->get();

    expect($plebs)->toHaveCount(1)
        ->and($plebs->first()->npub)->toBe((new NostrKey)->convertPublicKeyToBech32($pubkey))
        ->and($plebs->first()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($plebs->first()->association_status->value)->toBe(1);
});

it('creates no member record when the login fails', function (Closure $break) {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    $before = EinundzwanzigPleb::query()->count();

    expect(fn () => NostrAuth::loginWithSignedEvent($break($signedEvent)))
        ->toThrow(ValidationException::class);

    expect(EinundzwanzigPleb::query()->count())->toBe($before)
        ->and(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse()
        ->and(NostrAuth::check())->toBeFalse();
})->with([
    /*
     * The mutation closures sit DIRECTLY in the dataset — no `fn () =>` wrapper.
     * Pest only invokes a dataset entry when the whole dataset is callable
     * (DatasetsRepository::resolve(), `call_user_func($datasets[$index])`);
     * individual entries are handed to the test as-is. An outer wrapper would
     * therefore arrive as `$break`, and `$break($signedEvent)` would return the
     * still-unexecuted inner closure. That object then hits the `is_array()`
     * guard at the top of verifySignedEvent() and throws — green test, mutation
     * never applied, signature check never exercised.
     */
    'tampered signature' => function (array $event): array {
        $event['sig'] = ($event['sig'][0] === '0' ? '1' : '0').substr($event['sig'], 1);

        return $event;
    },
    'foreign pubkey with a valid looking signature' => function (array $event): array {
        $event['pubkey'] = str_repeat('a', 64);

        return $event;
    },
    'wrong kind' => function (array $event): array {
        $event['kind'] = 1;

        return $event;
    },
]);

it('creates no member record when the challenge does not match the session', function () {
    NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent('deadbeef'.str_repeat('0', 56));

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();
});

it('creates no member record when the event is outside the TTL window', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge, now()->subHour()->timestamp);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();
});

it('does not duplicate or overwrite the member record on a second login', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey, 'privkey' => $privkey] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($signedEvent);

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->firstOrFail();
    $pleb->update([
        'email' => 'member@example.com',
        'applied_at' => now()->subDay(),
    ]);
    $pleb->association_status = AssociationStatus::ACTIVE;
    $pleb->save();

    $original = $pleb->fresh();

    // Fresh session, fresh challenge, same identity — a returning member.
    NostrAuth::logout();
    $secondChallenge = NostrAuth::issueChallenge();
    ['event' => $secondEvent] = makeSignedLoginEvent($secondChallenge, null, $privkey);

    NostrAuth::loginWithSignedEvent($secondEvent);

    $after = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->get();

    expect($after)->toHaveCount(1)
        ->and($after->first()->id)->toBe($original->id)
        ->and($after->first()->email)->toBe('member@example.com')
        ->and($after->first()->applied_at->timestamp)->toBe($original->applied_at->timestamp)
        ->and($after->first()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($after->first()->npub)->toBe($original->npub);
});

it('does not allow a replay from a different (unauthenticated) session', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($signedEvent);

    // Simulate a fresh session: no challenge, no authenticated user.
    NostrAuth::logout();
    Session::forget(['nostr_login_challenge', 'nostr_login_challenge_expires_at']);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
});

/*
 * Jury run 1, B1: kind 22242 with a `challenge` tag is also the NIP-42 relay
 * AUTH event. A relay that passes our session challenge on as its own AUTH
 * challenge gets back exactly the event below from the member's client. It
 * must never be an app login.
 */

it('refuses the NIP-42 relay AUTH answer to the session challenge as a login', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge, tags: [
        ['relay', 'wss://attacker-relay.example/'],
        ['challenge', $challenge],
    ]);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse()
        ->and(Session::get('nostr_login_challenge'))->toBe($challenge);
});

it('refuses a login event with a relay tag even when it also names this app', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge, tags: [
        ['relay', 'wss://attacker-relay.example/'],
        ['challenge', $challenge],
        ['u', NostrAuth::loginUrl()],
    ]);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
});

it('refuses a login event that is not bound to this app by exactly one matching u tag', function (array $uTags) {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge, tags: [['challenge', $challenge], ...$uTags]);

    expect(fn () => NostrAuth::loginWithSignedEvent($signedEvent))
        ->toThrow(ValidationException::class)
        ->and(NostrAuth::check())->toBeFalse();
})->with([
    'no u tag' => [fn () => []],
    'another origin' => [fn () => [['u', 'https://other-app.example']]],
    'this origin with a path' => [fn () => [['u', NostrAuth::loginUrl().'/login']]],
    'this origin twice' => [fn () => [['u', NostrAuth::loginUrl()], ['u', NostrAuth::loginUrl()]]],
]);

it('binds the login to the origin of the configured app URL', function () {
    config(['app.url' => 'https://Verein.Example:8443/sub/path/']);

    expect(NostrAuth::loginUrl())->toBe('https://verein.example:8443');

    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge, tags: [
        ['challenge', $challenge],
        ['u', 'https://verein.example:8443'],
    ]);

    expect(NostrAuth::loginWithSignedEvent($signedEvent))->toBe($pubkey);
});

it('fails closed when the configured app URL has no host', function () {
    config(['app.url' => 'not a url']);
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent] = makeSignedLoginEvent($challenge, tags: [['challenge', $challenge], ['u', '']]);

    expect(NostrAuth::loginUrl())->toBeNull()
        ->and(fn () => NostrAuth::loginWithSignedEvent($signedEvent))->toThrow(ValidationException::class);
});

it('renders the app origin for the login client next to the challenge', function () {
    Livewire\Livewire::test('auth-button')
        ->assertSeeHtml('data-nostr-login-url="'.NostrAuth::loginUrl().'"');
});
