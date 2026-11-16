<?php

use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\EventHash;
use App\Services\Nostr\NostrRelayTransport;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\Relay\RelayAddressGuard;
use App\Services\Nostr\Relay\RelayConnectionFailed;
use App\Services\Nostr\RelayPublishStatus;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use swentel\nostr\Key\Key;
use Tests\Support\ScriptedRelayConnector;
use Tests\Support\TableHostResolver;

/*
 * The protocol half of the production transport, frame by frame. The relay
 * is a script (Tests\Support\ScriptedRelayConnector); DNS is a table. The
 * socket half — the real WebSocket connector — is in
 * NostrRelayTransportSocketTest.php.
 *
 * Mutation "decide only by event id, ignore the OK flag" (replace
 * `$ok === true ||` with `true ||` in NostrRelayTransport::exchangeEvent) →
 * the rejection cases go red.
 */

const RELAY = 'wss://relay.example.com';

function scriptedTransport(ScriptedRelayConnector $relay, ?string $secret = 'generate'): NostrRelayTransport
{
    return new NostrRelayTransport(
        new NotificationKey($secret === 'generate' ? testPrivateKey() : $secret),
        new RelayAddressGuard(new TableHostResolver(['relay.example.com' => ['93.184.215.14']])),
        $relay,
    );
}

/**
 * A real gift wrap, as the job will hand it over.
 *
 * @return array<string, mixed>
 */
function realWrap(): array
{
    $recipient = (new EinundzwanzigPleb)->forceFill(['pubkey' => (new Key)->getPublicKey(testPrivateKey())]);

    return (new DirectMessageSigner(new NotificationKey(testPrivateKey())))->wrapFor($recipient, 'Hallo')->wrap;
}

it('counts the event as accepted when the relay answers OK true for its id', function () {
    $event = realWrap();
    $relay = (new ScriptedRelayConnector)->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], true, '']);

    $result = scriptedTransport($relay)->publish(RELAY, $event);

    expect([$result->status, $result->accepted])->toBe([RelayPublishStatus::Accepted, true])
        ->and($relay->sent())->toBe([['EVENT', $event]])
        ->and($relay->connections()[0]->ip)->toBe('93.184.215.14')
        ->and($relay->wasClosed())->toBeTrue();
});

it('reports OK false as rejected, with the relay\'s reason', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, "blocked: kind 1059 not accepted\x07"]);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect([$result->status, $result->accepted, $result->message])
        ->toBe([RelayPublishStatus::Rejected, false, 'blocked: kind 1059 not accepted']);
});

it('counts a duplicate as accepted, whatever the flag says', function (bool $flag) {
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], $flag, 'duplicate: already have this event']);

    expect(scriptedTransport($relay)->publish(RELAY, realWrap())->status)->toBe(RelayPublishStatus::Accepted);
})->with(['flag true' => [true], 'flag false' => [false]]);

it('ignores everything that is not an OK for this event and lets the deadline decide', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(['OK', str_repeat('a', 64), true, ''])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], 'true', ''])
        ->reply('not json at all')
        ->reply(['EOSE', 'sub'])
        ->reply(null)
        ->reply(['NOTICE', 'slow down']);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect([$result->status, $result->message])
        ->toBe([RelayPublishStatus::Failed, 'timeout: no OK within 10 s (last NOTICE: slow down)']);
});

/*
 * Jury run 2, M-1: an AUTH greeting used to be answered at once, so every
 * inbox relay that greets with AUTH (khatru does on connect) learned that the
 * association key delivered this wrap. AUTH now waits for `auth-required:`.
 * Mutation "answer the greeting again" (call $authenticate() for every AUTH
 * frame in NostrRelayTransport::exchangeEvent) → the first case goes red.
 */
it('does not authenticate to a relay that greets with AUTH but accepts the event', function () {
    $event = realWrap();
    $relay = (new ScriptedRelayConnector)
        ->reply(['AUTH', 'challenge-abc'])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], true, '']);

    $result = scriptedTransport($relay)->publish(RELAY, $event);

    expect($result->status)->toBe(RelayPublishStatus::Accepted)
        ->and($relay->sentTypes())->toBe(['EVENT'])
        ->and($relay->sent())->toBe([['EVENT', $event]]);
});

it('authenticates with a kind 22242 by the notification key after auth-required and sends the event once more', function () {
    $secret = testPrivateKey();
    $event = realWrap();
    $relay = (new ScriptedRelayConnector)
        ->reply(['AUTH', 'challenge-abc'])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: gift wraps only from known users'])
        ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], true, ''])
        ->reply(fn (array $sent) => ['OK', $sent[2][1]['id'], true, '']);

    $result = scriptedTransport($relay, $secret)->publish(RELAY, $event);
    $auth = $relay->sent()[1][1];

    expect($result->status)->toBe(RelayPublishStatus::Accepted)
        ->and($relay->sentTypes())->toBe(['EVENT', 'AUTH', 'EVENT'])
        ->and($relay->sent()[2])->toBe(['EVENT', $event])
        ->and($auth['kind'])->toBe(22242)
        ->and($auth['pubkey'])->toBe((new Key)->getPublicKey($secret))
        ->and($auth['tags'])->toBe([['relay', RELAY], ['challenge', 'challenge-abc']])
        ->and($auth['id'])->toBe(EventHash::of($auth['pubkey'], $auth['created_at'], 22242, $auth['tags'], ''))
        ->and((new SchnorrSignature)->verify($auth['pubkey'], $auth['sig'], $auth['id']))->toBeTrue();
});

it('signs the latest challenge the relay offered, as NIP-42 says', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(['AUTH', 'first-challenge'])
        ->reply(['AUTH', 'second-challenge'])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: members only'])
        ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], true, ''])
        ->reply(fn (array $sent) => ['OK', $sent[2][1]['id'], true, '']);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect($result->status)->toBe(RelayPublishStatus::Accepted)
        ->and($relay->sent()[1][1]['tags'])->toBe([['relay', RELAY], ['challenge', 'second-challenge']]);
});

it('sends the event once more after auth-required, when its AUTH has been accepted', function () {
    $event = realWrap();
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: we only accept gift wraps from known users'])
        ->reply(['AUTH', 'late-challenge'])
        ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], true, ''])
        ->reply(fn (array $sent) => ['OK', $sent[2][1]['id'], true, '']);

    $result = scriptedTransport($relay)->publish(RELAY, $event);

    expect($result->status)->toBe(RelayPublishStatus::Accepted)
        ->and($relay->sentTypes())->toBe(['EVENT', 'AUTH', 'EVENT'])
        ->and($relay->sent()[2])->toBe(['EVENT', $event]);
});

it('fails cleanly when the relay refuses the event again after an accepted AUTH', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(['AUTH', 'challenge'])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: members only'])
        ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], true, ''])
        ->reply(fn (array $sent) => ['OK', $sent[2][1]['id'], false, 'auth-required: still members only'])
        ->reply(['AUTH', 'another-challenge']);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect([$result->status, $result->accepted, $result->message])
        ->toBe([RelayPublishStatus::Rejected, false, 'auth-required: still members only'])
        ->and($relay->sentTypes())->toBe(['EVENT', 'AUTH', 'EVENT'])
        ->and($relay->wasClosed())->toBeTrue();
});

it('rejects with both reasons when the relay refuses the AUTH it asked for', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(['AUTH', 'challenge'])
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: members only'])
        ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], false, 'restricted: not a member']);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect([$result->status, $result->message])
        ->toBe([RelayPublishStatus::Rejected, 'auth-required: members only (AUTH refused: restricted: not a member)'])
        ->and($relay->sentTypes())->toBe(['EVENT', 'AUTH']);
});

it('rejects an auth-required event whose relay never offers a challenge', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], false, 'auth-required: authenticate first']);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect([$result->status, $result->message])
        ->toBe([RelayPublishStatus::Rejected, 'auth-required: authenticate first (no AUTH completed before the deadline)']);
});

it('reports a relay that never answers as failed, not as rejected', function () {
    $result = scriptedTransport(new ScriptedRelayConnector)->publish(RELAY, realWrap());

    expect([$result->status, $result->accepted, $result->message])
        ->toBe([RelayPublishStatus::Failed, false, 'timeout: no OK within 10 s']);
});

it('maps every connection error to a failed result, a vendor TypeError included', function (Closure $arrange) {
    $relay = $arrange(new ScriptedRelayConnector);

    $result = scriptedTransport($relay)->publish(RELAY, realWrap());

    expect($result->status)->toBe(RelayPublishStatus::Failed)
        ->and($result->message)->toStartWith('failed: ');
})->with([
    'connect fails' => [fn (ScriptedRelayConnector $relay) => $relay->failToConnect(new RelayConnectionFailed('connect failed (ClientException)'))],
    'TypeError while reading' => [fn (ScriptedRelayConnector $relay) => $relay->reply(new TypeError('Cannot access offset of type string on null'))],
]);

it('throws the key error before connecting when no notification key is configured', function () {
    $relay = new ScriptedRelayConnector;

    expect(fn () => scriptedTransport($relay, null)->publish(RELAY, realWrap()))
        ->toThrow(NotificationKeyUnavailable::class)
        ->and($relay->connections())->toBe([]);
});

it('refuses without connecting what does not pass its own checks', function (string $url, Closure $event, string $reason) {
    $relay = new ScriptedRelayConnector;

    $result = scriptedTransport($relay)->publish($url, $event());

    expect($result->status)->toBe(RelayPublishStatus::Refused)
        ->and($result->message)->toContain($reason)
        ->and($relay->connections())->toBe([]);
})->with([
    'plain ws' => ['ws://relay.example.com', fn () => realWrap(), 'only wss://'],
    'loopback' => ['wss://127.0.0.1:7777', fn () => realWrap(), 'non-public'],
    'private address' => ['wss://192.168.178.1', fn () => realWrap(), 'non-public'],
    'link-local' => ['wss://169.254.169.254', fn () => realWrap(), 'non-public'],
    'name without address' => ['wss://unknown.example.com', fn () => realWrap(), 'does not resolve'],
    'event without id' => [RELAY, fn () => ['kind' => 1059], 'no valid id'],
    'oversized event' => [RELAY, fn () => array_merge(realWrap(), ['content' => str_repeat('a', 70_000)]), 'larger than'],
]);

it('collects events until EOSE and closes its subscription', function () {
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['EVENT', $sent[0][1], ['id' => 'one', 'kind' => 10050]])
        ->reply(['EVENT', 'someone-elses-subscription', ['id' => 'stray']])
        ->reply(fn (array $sent) => ['EVENT', $sent[0][1], ['id' => 'two', 'kind' => 10050]])
        ->reply(fn (array $sent) => ['EOSE', $sent[0][1]]);

    $result = scriptedTransport($relay, null)->fetch(RELAY, ['kinds' => [10050], 'authors' => ['ab']]);
    [$req, $close] = $relay->sent();

    expect([$result->complete, array_column($result->events, 'id')])->toBe([true, ['one', 'two']])
        ->and($req[0])->toBe('REQ')
        ->and($req[2])->toBe(['kinds' => [10050], 'authors' => ['ab']])
        ->and($close)->toBe(['CLOSE', $req[1]]);
});

it('returns an incomplete result when the relay closes the subscription or goes quiet', function (?array $last, string $message) {
    $relay = (new ScriptedRelayConnector)
        ->reply(fn (array $sent) => ['EVENT', $sent[0][1], ['id' => 'partial']]);

    if ($last !== null) {
        $relay->reply(fn (array $sent) => [$last[0], $sent[0][1], $last[1]]);
    }

    $result = scriptedTransport($relay, null)->fetch(RELAY, ['kinds' => [10050]]);

    expect([$result->complete, array_column($result->events, 'id'), $result->message])
        ->toBe([false, ['partial'], $message]);
})->with([
    'CLOSED' => [['CLOSED', 'auth-required: members only'], 'closed: auth-required: members only'],
    'deadline' => [null, 'timeout: no EOSE within 10 s'],
]);

it('reads without a notification key and without connecting to a refused address', function () {
    $relay = new ScriptedRelayConnector;

    $result = scriptedTransport($relay, null)->fetch('wss://10.0.0.1', ['kinds' => [10050]]);

    expect([$result->complete, $result->events])->toBe([false, []])
        ->and($result->message)->toContain('non-public')
        ->and($relay->connections())->toBe([]);
});
