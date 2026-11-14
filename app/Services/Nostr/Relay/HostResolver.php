<?php

namespace App\Services\Nostr\Relay;

/**
 * Turns a host name into the addresses it points to.
 *
 * A seam of its own because a DNS lookup is network traffic too: tests bind a
 * resolver that answers from a table, and the address guard is tested against
 * answers no real DNS would give on demand (a public name that resolves to
 * 10.0.0.1).
 */
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the host resolves to; empty if none.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array;
}
