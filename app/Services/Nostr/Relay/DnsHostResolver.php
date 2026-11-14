<?php

namespace App\Services\Nostr\Relay;

/**
 * A and AAAA records from the system's DNS resolver.
 *
 * dns_get_record() rather than gethostbynamel(): the latter knows IPv4 only,
 * and a host whose AAAA record points at ::1 would pass a check that never
 * saw it.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
