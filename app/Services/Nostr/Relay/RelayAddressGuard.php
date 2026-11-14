<?php

namespace App\Services\Nostr\Relay;

/**
 * Decides whether the server may open a socket to a relay URL, and to which
 * address.
 *
 * Relay URLs come from members' kind 10050 lists: anyone can publish one, so
 * each URL is input from a stranger to a socket this server opens. The rules:
 *
 * - `wss://` only. No `ws://`, no other scheme, no user info, no fragment.
 * - A host name is resolved here, once. If ANY of its addresses is not a
 *   public unicast address, the URL is refused — a name that answers with
 *   one public and one private address must not get a second chance at
 *   connect time. The connection is then made to the address checked here
 *   (PinnedRelayAddress), so a DNS answer that changes between check and
 *   connect cannot redirect it.
 * - "Public" is PHP's FILTER_FLAG_GLOBAL_RANGE (RFC 6890: private, loopback,
 *   link-local, unique-local, CGNAT, documentation, reserved, IPv4-mapped,
 *   6to4 …) plus the ranges that flag lets through, measured on PHP 8.5 on
 *   2026-11-14: multicast (224.0.0.0/4, ff00::/8), NAT64 (64:ff9b::/96,
 *   64:ff9b:1::/48), IPv4-compatible IPv6 (::/96) and the old 6to4 relay
 *   anycast 192.88.99.0/24.
 *
 * Redirects are not followed by the WebSocket client (no FollowRedirect
 * middleware is installed), so a relay cannot hand the connection on.
 */
final class RelayAddressGuard
{
    public const int MAX_URL_LENGTH = 512;

    /** @var array<int, string> */
    private const array DENIED_RANGES = [
        '224.0.0.0/4',
        '192.88.99.0/24',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '::/96',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws RelayAddressRefused
     */
    public function pin(string $url): PinnedRelayAddress
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw RelayAddressRefused::because('relay URL is longer than '.self::MAX_URL_LENGTH.' characters');
        }

        $parts = parse_url(trim($url));

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'wss') {
            throw RelayAddressRefused::because('only wss:// relay URLs are allowed');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw RelayAddressRefused::because('relay URL carries user info or a fragment');
        }

        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $port = $parts['port'] ?? 443;

        if ($host === '' || $port < 1 || $port > 65535) {
            throw RelayAddressRefused::because('relay URL has no usable host or port');
        }

        $pathAndQuery = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];

        if (isset($parts['query'])) {
            $pathAndQuery .= '?'.$parts['query'];
        }

        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$literal];
            $host = $literal;
        } else {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1) {
                throw RelayAddressRefused::because('relay host is not a plain DNS name');
            }

            if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
                throw RelayAddressRefused::because('relay host is local');
            }

            $addresses = $this->resolver->resolve($host);

            if ($addresses === []) {
                throw RelayAddressRefused::because('relay host does not resolve');
            }
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw RelayAddressRefused::because('relay host resolves to a non-public address');
            }
        }

        $normalisedHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $normalisedPort = $port === 443 ? '' : ":{$port}";

        return new PinnedRelayAddress(
            url: "wss://{$normalisedHost}{$normalisedPort}{$pathAndQuery}",
            host: $host,
            port: $port,
            pathAndQuery: $pathAndQuery,
            ip: $addresses[0],
        );
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        foreach (self::DENIED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range);

        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if (strncmp($addressBytes, $networkBytes, $fullBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
