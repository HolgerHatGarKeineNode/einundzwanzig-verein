<?php

namespace App\Services\Nostr\Relay;

/**
 * One open WebSocket to one relay, reduced to what NIP-01 needs.
 */
interface RelayConnection
{
    /**
     * @throws RelayConnectionFailed
     */
    public function send(string $frame): void;

    /**
     * The next text frame, or null for a frame that carries no text (ping,
     * pong, binary). Blocks until a frame arrives or the deadline passes.
     *
     * @throws RelayConnectionTimedOut when the deadline passed
     * @throws RelayConnectionFailed when the connection broke
     */
    public function receive(): ?string;

    /**
     * Never throws.
     */
    public function close(): void;
}
