<?php

namespace App\Services\Nostr\Relay\WebSocket;

use App\Services\Nostr\Relay\PinnedRelayAddress;
use App\Services\Nostr\Relay\RelayConnection;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use App\Services\Nostr\Relay\RelayConnector;
use Throwable;
use WebSocket\Client;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * The one class that opens a WebSocket for the direct-message transport.
 *
 * - TCP goes to the pinned address, TLS is verified against the host name.
 * - No redirects: phrity follows a 3xx only with its FollowRedirect
 *   middleware, which is not installed here; any status but 101 fails.
 * - No permessage-deflate: the compression middleware is not installed, so a
 *   relay cannot send a small frame that inflates beyond the byte budget.
 * - Connect timeout, overall deadline and byte budget as in
 *   BoundedSocketStream.
 */
final class WebSocketRelayConnector implements RelayConnector
{
    public const float CONNECT_TIMEOUT_SECONDS = 5.0;

    public const int MAX_INBOUND_BYTES = 1_048_576;

    public function __construct(
        private readonly float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
        private readonly int $maxInboundBytes = self::MAX_INBOUND_BYTES,
    ) {}

    public function connect(PinnedRelayAddress $address, float $deadline): RelayConnection
    {
        $remaining = $deadline - hrtime(true) / 1e9;

        if ($remaining <= 0) {
            throw new RelayConnectionTimedOut('The relay deadline passed before connecting.');
        }

        $factory = new BoundedStreamFactory(
            $address->isIpv6() ? "[{$address->ip}]" : $address->ip,
            $deadline,
            $this->maxInboundBytes,
        );

        $hostIsLiteral = filter_var($address->host, FILTER_VALIDATE_IP) !== false;
        $authority = str_contains($address->host, ':') ? "[{$address->host}]" : $address->host;

        try {
            $client = new Client("wss://{$authority}:{$address->port}{$address->pathAndQuery}", null, $factory);

            $client
                ->setTimeout(min($this->connectTimeoutSeconds, $remaining))
                ->setContext(['ssl' => [
                    'peer_name' => $address->host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    // RFC 6066: SNI carries host names, never addresses.
                    'SNI_enabled' => ! $hostIsLiteral,
                    'SNI_server_name' => $address->host,
                ]])
                ->addMiddleware(new CloseHandler)
                ->addMiddleware(new PingResponder);

            $client->connect();
        } catch (Throwable $exception) {
            throw WebSocketRelayConnection::translate($exception, $factory, $deadline, 'connect');
        }

        return new WebSocketRelayConnection($client, $factory, $deadline);
    }
}
