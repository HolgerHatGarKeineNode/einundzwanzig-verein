<?php

namespace App\Services\Nostr\Relay;

/**
 * Opens a WebSocket to a relay address that already passed RelayAddressGuard.
 *
 * Separate from the protocol logic in NostrRelayTransport so that logic can be
 * tested frame by frame without a network, and so the one class that dials is
 * small enough to read in one sitting.
 */
interface RelayConnector
{
    /**
     * @param  float  $deadline  monotonic time in seconds (hrtime) after which every operation fails
     *
     * @throws RelayConnectionFailed
     */
    public function connect(PinnedRelayAddress $address, float $deadline): RelayConnection;
}
