<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Thrown where a test tried to reach a relay nobody faked.
 *
 * The relay counterpart of Illuminate's StrayRequestException. It is not the
 * whole guard: the vendor WebSocket client catches every Throwable on connect
 * and rethrows its own exception, and application code catches that in turn.
 * RelayTripwire therefore records each attempt as well and fails the test
 * afterwards.
 */
final class StrayRelayConnection extends RuntimeException
{
    public static function throughTransport(string $operation, string $relayUrl): self
    {
        return new self(
            "Attempted to {$operation} on relay [{$relayUrl}] without a fake. "
            .'Bind Tests\Support\FakeRelayTransport in the test that needs a relay.',
        );
    }

    public static function atSocket(string $address): self
    {
        return new self(
            "Attempted to open a socket to [{$address}] from a test. "
            .'Relay access goes through App\Services\Nostr\RelayTransport; '
            .'a loopback relay needs RelayTripwire::allowLoopback().',
        );
    }
}
