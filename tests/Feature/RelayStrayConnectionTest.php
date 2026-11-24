<?php

use App\Services\Nostr\RelayTransport;
use Phrity\Net\SocketClient;
use Phrity\Net\Uri;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Nip17\DirectMessage;
use swentel\nostr\Nip59\GiftWrapService;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Process\Process;
use Tests\Support\RelayTripwire;
use Tests\Support\StrayRelayConnection;

/*
 * The relay guard from tests/Pest.php, proven by its effect — the relay
 * counterpart of HttpStrayRequestTest.
 *
 * Every case asks what a test would actually suffer if the guard were gone:
 * a socket that got through, or a refusal nobody heard of. None of them
 * asserts that a hook is registered or a function defined.
 *
 * No case reaches a third party even when it fails. The sockets that may
 * really open go to a listener this file starts on 127.0.0.1, the one address
 * beyond loopback is from a range that is never routed, and the case that
 * calls vendor code with hard-wired public relays refuses to start unless the
 * socket guard is loaded.
 *
 * Mutations (patches under docs/plans/…/mutations/, not in the repository):
 * - M0b  drop the require of phrity-net-socket-shadow.php from tests/Pest.php
 *        → the two vendor cases go red.
 * - M0c  drop the afterEach line for Feature from tests/Pest.php
 *        → the swallowed-refusal case goes red.
 * - M11  bind a transport that does not refuse as the test default
 *        → the two unfaked-transport cases go red.
 */

/**
 * A listener on a free loopback port, so a dial that slips through has
 * somewhere harmless to land and leaves a trace.
 *
 * @return array{0: resource, 1: int}
 */
function relayStrayListener(): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);

    return [$listener, $port];
}

/**
 * Whether a connection reached the listener. Waits for the condition, not for
 * a fixed time: it returns the moment a connection is queued.
 *
 * @param  resource  $listener
 */
function relayStrayListenerWasReached($listener, int $waitSeconds = 0): bool
{
    $read = [$listener];
    $write = $except = null;

    return stream_select($read, $write, $except, $waitSeconds) === 1;
}

it('refuses a relay call that no test faked and names the relay', function (string $operation, Closure $call) {
    expect(fn () => $call(app(RelayTransport::class)))
        ->toThrow(StrayRelayConnection::class, 'wss://relay.example/');

    expect(RelayTripwire::takeAttempts())->toBe([
        ['layer' => 'transport', 'operation' => $operation, 'target' => 'wss://relay.example/'],
    ]);
})->with([
    'publish' => ['publish', fn (RelayTransport $relay) => $relay->publish('wss://relay.example/', ['kind' => 1])],
    'fetch' => ['fetch', fn (RelayTransport $relay) => $relay->fetch('wss://relay.example/', ['kinds' => [10050]])],
]);

/*
 * The existing call sites build a vendor Relay themselves and never pass the
 * seam. What stops them is the socket.
 */
it('stops a vendor relay that bypasses the seam before it opens a socket', function () {
    [$listener, $port] = relayStrayListener();

    $note = new Event;
    $note->setKind(1);
    $note->setContent('never sent');
    (new Sign)->signEvent($note, testPrivateKey());

    $relay = new Relay("ws://127.0.0.1:{$port}");
    $relay->setMessage(new EventMessage($note));
    // Bounds the wait for a handshake answer, should the dial get through.
    $relay->getClient()->setTimeout(1);

    try {
        $relay->send();
    } catch (Throwable) {
        // The vendor reports a refused dial in its own way; not the subject here.
    }

    expect(relayStrayListenerWasReached($listener))->toBeFalse()
        ->and(RelayTripwire::takeAttempts())->toBe([
            ['layer' => 'socket', 'operation' => 'connect', 'target' => "tcp://127.0.0.1:{$port}"],
        ]);
});

/*
 * DirectMessage::sendDirectMessage() looks the recipient's relays up through
 * DmRelaysList and RelayListMetadata, both of which dial public relays that
 * are literals in the vendor source. Blank relay variables do not reach them.
 *
 * The vendor swallows every refusal on that path and returns as if the lookup
 * had found nothing, so the only evidence is the ledger.
 */
it('stops the vendor NIP-17 path, which dials public relays whatever the configuration says', function () {
    if (! function_exists('Phrity\Net\stream_socket_client')) {
        $this->fail('The socket guard is not loaded. Not calling vendor code that dials public relays.');
    }

    $key = new Key;
    $directMessage = new DirectMessage(new GiftWrapService($key, new Sign), $key);

    try {
        $directMessage->sendDirectMessage(testPrivateKey(), $key->getPublicKey(testPrivateKey()), 'never sent');
    } catch (Throwable) {
        // Whether the vendor throws or returns is not the subject here.
    }

    $attempts = RelayTripwire::takeAttempts();

    expect(array_values(array_unique(array_column($attempts, 'layer'))))->toBe(['socket'])
        ->and(array_column($attempts, 'target'))->toContain('ssl://relay.damus.io:443');
});

/*
 * A refusal is only an exception, and exceptions get caught: by phrity's
 * Client::connect(), by swentel's Request::send(), by a catch-all in application code. The
 * child process runs a test whose body is green after swallowing one — the
 * check after the test has to fail it regardless.
 */
it('fails a test that swallowed the refusal', function () {
    $child = new Process(
        [PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/Fixtures/SwallowedRelayAttempt.php', '--colors=never'],
        base_path(),
    );
    $child->run();

    expect($child->getExitCode())->toBe(1)
        ->and($child->getOutput())
        ->toContain('This test tried to reach a relay and the refusal was swallowed')
        ->toContain('transport publish wss://relay.example/')
        ->toContain('1 failed');
});

it('opens loopback only for a test that asked for it, and nothing beyond loopback', function () {
    [$listener, $port] = relayStrayListener();
    $dial = fn (string $address) => (new SocketClient(new Uri($address)))->setTimeout(1)->connect();

    expect(fn () => $dial("tcp://127.0.0.1:{$port}"))->toThrow(StrayRelayConnection::class)
        ->and(relayStrayListenerWasReached($listener))->toBeFalse();

    RelayTripwire::allowLoopback();

    $dial("tcp://127.0.0.1:{$port}");

    // 192.0.2.1 is TEST-NET-1 (RFC 5737): never routed, should it be dialled.
    expect(relayStrayListenerWasReached($listener, waitSeconds: 5))->toBeTrue()
        ->and(fn () => $dial('tcp://192.0.2.1:443'))->toThrow(StrayRelayConnection::class)
        ->and(RelayTripwire::takeAttempts())->toBe([
            ['layer' => 'socket', 'operation' => 'connect', 'target' => "tcp://127.0.0.1:{$port}"],
            ['layer' => 'socket', 'operation' => 'connect', 'target' => 'tcp://192.0.2.1:443'],
        ]);
});

it('closes loopback again when the next test is armed', function () {
    [, $port] = relayStrayListener();

    RelayTripwire::allowLoopback();
    RelayTripwire::arm(app());

    expect(fn () => (new SocketClient(new Uri("tcp://127.0.0.1:{$port}")))->connect())
        ->toThrow(StrayRelayConnection::class);

    RelayTripwire::takeAttempts();
});
