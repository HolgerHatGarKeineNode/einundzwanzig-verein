<?php

use App\Models\EinundzwanzigPleb;
use App\Providers\AppServiceProvider;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\NostrRelayTransport;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\Relay\DnsHostResolver;
use App\Services\Nostr\Relay\HostResolver;
use App\Services\Nostr\Relay\RelayAddressGuard;
use App\Services\Nostr\Relay\RelayConnectionFailed;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use App\Services\Nostr\Relay\RelayConnector;
use App\Services\Nostr\Relay\WebSocket\BoundedSocketStream;
use App\Services\Nostr\Relay\WebSocket\WebSocketRelayConnector;
use App\Services\Nostr\RelayPublishStatus;
use App\Services\Nostr\RelayTransport;
use swentel\nostr\Key\Key;
use Tests\Support\RefusingRelayTransport;
use Tests\Support\RelayTripwire;
use Tests\Support\TableHostResolver;
use WebSocket\Frame\FrameHandler;

/*
 * The production transport with its real WebSocket connector. Nothing here
 * leaves the machine: the socket shadow from tests/Pest.php stops the vendor
 * at the dial and records the address it was about to dial. That record is
 * the evidence — which address, or that there was no dial at all.
 *
 * The bounded stream is tested on a local socket pair: a relay that announces
 * a huge frame or never answers is simulated by what is written into the
 * other end.
 */

function socketTransport(?string $secret = 'generate'): NostrRelayTransport
{
    return new NostrRelayTransport(
        new NotificationKey($secret === 'generate' ? testPrivateKey() : $secret),
        new RelayAddressGuard(new TableHostResolver([
            'relay.example.com' => ['93.184.215.14'],
            'rebind.example.com' => ['10.0.0.7'],
        ])),
        new WebSocketRelayConnector,
    );
}

/**
 * @return array<string, mixed>
 */
function socketTestWrap(): array
{
    $recipient = (new EinundzwanzigPleb)->forceFill(['pubkey' => (new Key)->getPublicKey(testPrivateKey())]);

    return (new DirectMessageSigner(new NotificationKey(testPrivateKey())))->wrapFor($recipient, 'Hallo')->wrap;
}

it('binds the real transport outside the test environment and the refusing one inside it', function () {
    expect(app(RelayTransport::class))->toBeInstanceOf(RefusingRelayTransport::class);

    app()->detectEnvironment(fn (): string => 'production');
    (new AppServiceProvider(app()))->register();

    expect(app(RelayTransport::class))->toBeInstanceOf(NostrRelayTransport::class)
        ->and(app(HostResolver::class))->toBeInstanceOf(DnsHostResolver::class)
        ->and(app(RelayConnector::class))->toBeInstanceOf(WebSocketRelayConnector::class)
        ->and(RelayTripwire::takeAttempts())->toBe([]);
});

it('dials the address the guard checked, not the host name, and reports the failure as a result', function (string $url, string $dialled) {
    $result = socketTransport()->publish($url, socketTestWrap());

    expect($result->status)->toBe(RelayPublishStatus::Failed)
        ->and($result->message)->toStartWith('failed: connect failed')
        ->and(RelayTripwire::takeAttempts())->toBe([
            ['layer' => 'socket', 'operation' => 'connect', 'target' => $dialled],
        ]);
})->with([
    'host name' => ['wss://relay.example.com', 'ssl://93.184.215.14:443'],
    'IPv6 literal with port' => ['wss://[2606:4700::1]:8443/inbox', 'ssl://[2606:4700::1]:8443'],
]);

it('refuses a non-public or non-wss relay before any socket is opened', function (string $url) {
    $result = socketTransport()->publish($url, socketTestWrap());

    expect($result->status)->toBe(RelayPublishStatus::Refused)
        ->and(RelayTripwire::takeAttempts())->toBe([]);
})->with([
    'plain ws' => ['ws://relay.example.com'],
    'loopback' => ['wss://127.0.0.1:4869'],
    'IPv6 loopback' => ['wss://[::1]'],
    'private' => ['wss://10.1.2.3'],
    'link-local' => ['wss://169.254.169.254'],
    'IPv6 link-local' => ['wss://[fe80::1]'],
    'name resolving to a private address' => ['wss://rebind.example.com'],
]);

it('throws the typed key error before any socket is opened', function () {
    expect(fn () => socketTransport(null)->publish('wss://relay.example.com', socketTestWrap()))
        ->toThrow(NotificationKeyUnavailable::class)
        ->and(RelayTripwire::takeAttempts())->toBe([]);
});

it('counts the DNS lookup against the deadline and dials nothing once it is spent', function (string $operation) {
    $slowDns = new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            usleep(300_000);

            return ['93.184.215.14'];
        }
    };
    $transport = new NostrRelayTransport(
        new NotificationKey(testPrivateKey()),
        new RelayAddressGuard($slowDns),
        new WebSocketRelayConnector,
        deadlineSeconds: 0.2,
    );
    $wrap = socketTestWrap();

    $message = $operation === 'publish'
        ? $transport->publish('wss://relay.example.com', $wrap)->message
        : $transport->fetch('wss://relay.example.com', ['kinds' => [10050]])->message;

    expect($message)->toStartWith('timeout:')
        ->and(RelayTripwire::takeAttempts())->toBe([]);
})->with(['publish', 'fetch']);

it('reports a failed read as an incomplete result, not as an empty one', function () {
    $result = socketTransport(null)->fetch('wss://relay.example.com', ['kinds' => [10050]]);

    expect([$result->complete, $result->events])->toBe([false, []])
        ->and($result->message)->toStartWith('failed: connect failed')
        ->and(RelayTripwire::takeAttempts())->toHaveCount(1);
});

/**
 * @return array{0: BoundedSocketStream, 1: resource}
 */
function boundedPair(float $secondsLeft, int $budget): array
{
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    return [new BoundedSocketStream($ours, hrtime(true) / 1e9 + $secondsLeft, $budget), $theirs];
}

it('refuses a frame that announces more than the budget, before reading it', function () {
    [$stream, $relay] = boundedPair(5.0, 1024);

    // A text frame header announcing 2^62 bytes of payload.
    fwrite($relay, "\x81\x7f".pack('J', 1 << 62));

    expect(fn () => (new FrameHandler($stream, false, false))->pull())
        ->toThrow(RelayConnectionFailed::class, 'more than 1024 bytes')
        ->and($stream->failure())->toBe(BoundedSocketStream::FAILURE_TOO_LARGE);
});

it('stops a relay that sends more than the budget in small pieces', function () {
    [$stream, $relay] = boundedPair(5.0, 10);

    fwrite($relay, str_repeat("header: x\r\n", 3));

    expect(fn () => [$stream->readLine(1024), $stream->readLine(1024)])
        ->toThrow(RelayConnectionFailed::class)
        ->and($stream->failure())->toBe(BoundedSocketStream::FAILURE_TOO_LARGE);
});

it('gives up at the deadline when the relay sends nothing', function () {
    // The other end stays open and silent; were it closed, the read would end in EOF instead.
    [$stream, $silentRelay] = boundedPair(0.3, 1024);
    $started = hrtime(true);

    expect(fn () => $stream->read(2))->toThrow(RelayConnectionTimedOut::class)
        ->and($stream->failure())->toBe(BoundedSocketStream::FAILURE_TIMEOUT)
        ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThan(0.2)->toBeLessThan(2.0)
        ->and(is_resource($silentRelay))->toBeTrue();
});

it('holds the deadline against a relay that drips the handshake line one byte at a time', function () {
    [$stream, $relay] = boundedPair(1.0, 1024);

    // A child process owns the other end and writes one byte every 0.4 s: every
    // single recv() gets its byte well inside any per-read timeout.
    $dripper = proc_open(
        [PHP_BINARY, '-r', 'foreach (str_split("HTTP/1.1 101 Switching Protocols\r\n") as $byte) { fwrite(STDOUT, $byte); fflush(STDOUT); usleep(400000); }'],
        [0 => ['pipe', 'r'], 1 => $relay, 2 => ['pipe', 'w']],
        $pipes,
    );
    fclose($relay);
    $started = hrtime(true);

    try {
        expect(fn () => $stream->readLine(1024))->toThrow(RelayConnectionTimedOut::class)
            ->and($stream->failure())->toBe(BoundedSocketStream::FAILURE_TIMEOUT)
            ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThan(0.9)->toBeLessThan(1.5);
    } finally {
        proc_terminate($dripper, 9);
        proc_close($dripper);
    }
});

it('reads a header line that arrives at once, up to and including the line break', function () {
    [$stream, $relay] = boundedPair(5.0, 1024);

    fwrite($relay, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n");
    fclose($relay);

    expect([$stream->readLine(1024), $stream->readLine(8), $stream->readLine(1024), $stream->readLine(1024)])
        ->toBe(["HTTP/1.1 101 Switching Protocols\r\n", 'Upgrade', ": websocket\r\n", null]);
});

it('refuses to write once the deadline has passed', function () {
    [$stream] = boundedPair(-1.0, 1024);

    expect(fn () => $stream->write('["EVENT",{}]'))->toThrow(RelayConnectionTimedOut::class);
});
