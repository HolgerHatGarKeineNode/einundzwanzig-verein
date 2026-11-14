<?php

namespace App\Services\Nostr\Relay\WebSocket;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\SocketStream;
use Psr\Http\Message\UriInterface;

/**
 * The vendor socket client, with the opened socket handed over to a
 * BoundedSocketStream.
 *
 * The dial itself stays in the parent class, inside the Phrity\Net
 * namespace — which is what lets the test suite's socket shadow
 * (tests/Support/phrity-net-socket-shadow.php) see and stop it.
 */
final class BoundedSocketClient extends SocketClient
{
    public function __construct(
        UriInterface $uri,
        ?Context $context,
        private readonly BoundedStreamFactory $factory,
    ) {
        parent::__construct($uri, $context);
    }

    public function connect(): SocketStream
    {
        return $this->factory->adopt(parent::connect()->detach());
    }
}
