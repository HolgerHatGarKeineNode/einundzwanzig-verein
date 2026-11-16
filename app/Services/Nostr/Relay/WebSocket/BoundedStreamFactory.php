<?php

namespace App\Services\Nostr\Relay\WebSocket;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\StreamFactory;
use Psr\Http\Message\UriInterface;

/**
 * Hands phrity/websocket a socket client that dials the pinned address
 * instead of the host name, and wraps the opened socket in a
 * BoundedSocketStream.
 *
 * The host name stays in the WebSocket URI, so the HTTP Host header and TLS
 * (SNI, certificate check via the `peer_name` context option) still use it;
 * only the TCP connection goes to the address RelayAddressGuard checked.
 */
final class BoundedStreamFactory extends StreamFactory
{
    public ?BoundedSocketStream $stream = null;

    /**
     * @param  string  $pinnedHost  an IPv4 address, or an IPv6 address in brackets
     */
    public function __construct(
        private readonly string $pinnedHost,
        private readonly float $deadline,
        private readonly int $maxInboundBytes,
    ) {
        parent::__construct();
    }

    public function createSocketClient(UriInterface $uri, ?Context $context = null): SocketClient
    {
        return new BoundedSocketClient($uri->withHost($this->pinnedHost), $context, $this);
    }

    /**
     * @param  resource  $resource
     */
    public function adopt($resource): BoundedSocketStream
    {
        return $this->stream = new BoundedSocketStream($resource, $this->deadline, $this->maxInboundBytes);
    }
}
