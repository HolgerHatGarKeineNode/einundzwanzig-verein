<?php

namespace App\Services\Nostr\Relay;

/**
 * A relay URL that passed RelayAddressGuard, with the one address it may be
 * dialled at.
 *
 * The connection goes to `ip`; `host` is used only for the HTTP Host header
 * and for TLS (SNI and certificate check). A second DNS lookup at connect
 * time — which could answer differently — never happens.
 */
final readonly class PinnedRelayAddress
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $pathAndQuery,
        public string $ip,
    ) {}

    public function isIpv6(): bool
    {
        return str_contains($this->ip, ':');
    }
}
