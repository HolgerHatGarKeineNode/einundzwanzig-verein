<?php

namespace Tests\Support;

use App\Services\Nostr\RelayPublishResult;
use App\Services\Nostr\RelayTransport;

/**
 * The transport every test gets unless it binds a fake: it reaches nothing,
 * records the attempt and throws.
 */
final class RefusingRelayTransport implements RelayTransport
{
    public function publish(string $relayUrl, array $event): RelayPublishResult
    {
        RelayTripwire::record('transport', 'publish', $relayUrl);

        throw StrayRelayConnection::throughTransport('publish', $relayUrl);
    }

    public function fetch(string $relayUrl, array $filter): array
    {
        RelayTripwire::record('transport', 'fetch', $relayUrl);

        throw StrayRelayConnection::throughTransport('fetch', $relayUrl);
    }
}
