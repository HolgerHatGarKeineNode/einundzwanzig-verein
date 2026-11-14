<?php

use App\Services\Nostr\Relay\HostResolver;
use App\Services\Nostr\Relay\RelayAddressGuard;
use App\Services\Nostr\Relay\RelayAddressRefused;

/*
 * Relay URLs come from strangers' kind 10050 lists. The guard is the only
 * thing between such a URL and a socket opened by this server, so every
 * refusal reason has a case, and the DNS answers are ones a real resolver
 * would never give on demand.
 */

/**
 * A resolver that answers from a table and remembers what it was asked.
 */
function tableResolver(array $answers): HostResolver
{
    return new class($answers) implements HostResolver
    {
        /** @var array<int, string> */
        public array $asked = [];

        public function __construct(private readonly array $answers) {}

        public function resolve(string $host): array
        {
            $this->asked[] = $host;

            return $this->answers[$host] ?? [];
        }
    };
}

it('refuses a URL before any lookup when its form is wrong', function (string $url) {
    $resolver = tableResolver([]);

    expect(fn () => (new RelayAddressGuard($resolver))->pin($url))
        ->toThrow(RelayAddressRefused::class)
        ->and($resolver->asked)->toBe([]);
})->with([
    'plain ws' => ['ws://relay.example.com'],
    'https' => ['https://relay.example.com'],
    'no scheme' => ['relay.example.com'],
    'user info' => ['wss://user:pass@relay.example.com'],
    'fragment' => ['wss://relay.example.com/#x'],
    'no host' => ['wss:///path'],
    'underscore host' => ['wss://re_lay.example.com'],
    'single label' => ['wss://intranet'],
    'localhost' => ['wss://localhost'],
    'subdomain of localhost' => ['wss://relay.localhost'],
    'too long' => ['wss://relay.example.com/'.str_repeat('a', RelayAddressGuard::MAX_URL_LENGTH)],
]);

it('refuses an address literal that is not public', function (string $url) {
    expect(fn () => (new RelayAddressGuard(tableResolver([])))->pin($url))
        ->toThrow(RelayAddressRefused::class, 'non-public');
})->with([
    'loopback' => ['wss://127.0.0.1'],
    'loopback, other address' => ['wss://127.10.0.3:7777'],
    'private 10/8' => ['wss://10.0.0.1'],
    'private 172.16/12' => ['wss://172.16.5.4'],
    'private 192.168/16' => ['wss://192.168.1.1'],
    'link-local, cloud metadata' => ['wss://169.254.169.254'],
    'carrier-grade NAT' => ['wss://100.64.0.1'],
    'unspecified' => ['wss://0.0.0.0'],
    'multicast' => ['wss://224.0.0.1'],
    'IPv6 loopback' => ['wss://[::1]'],
    'IPv6 link-local' => ['wss://[fe80::1]'],
    'IPv6 unique-local' => ['wss://[fd12:3456::1]'],
    'IPv4-mapped loopback' => ['wss://[::ffff:127.0.0.1]'],
    'NAT64 of loopback' => ['wss://[64:ff9b::7f00:1]'],
    'IPv4-compatible loopback' => ['wss://[::7f00:1]'],
    'IPv6 multicast' => ['wss://[ff02::1]'],
]);

it('refuses a name when any of its addresses is not public', function (array $addresses) {
    expect(fn () => (new RelayAddressGuard(tableResolver(['relay.example.com' => $addresses])))->pin('wss://relay.example.com'))
        ->toThrow(RelayAddressRefused::class, 'non-public');
})->with([
    'private only' => [['10.0.0.1']],
    'public first, then loopback' => [['93.184.215.14', '127.0.0.1']],
    'public IPv4, link-local IPv6' => [['93.184.215.14', 'fe80::1']],
]);

it('refuses a name that does not resolve', function () {
    expect(fn () => (new RelayAddressGuard(tableResolver([])))->pin('wss://gone.example.com'))
        ->toThrow(RelayAddressRefused::class, 'does not resolve');
});

it('pins a public name to the address it was checked at', function () {
    $resolver = tableResolver(['relay.example.com' => ['93.184.215.14', '2606:4700::1']]);

    $pinned = (new RelayAddressGuard($resolver))->pin('WSS://Relay.Example.com.:8443/inbox?auth=1');

    expect([$pinned->url, $pinned->host, $pinned->port, $pinned->pathAndQuery, $pinned->ip, $pinned->isIpv6()])
        ->toBe(['wss://relay.example.com:8443/inbox?auth=1', 'relay.example.com', 8443, '/inbox?auth=1', '93.184.215.14', false])
        ->and($resolver->asked)->toBe(['relay.example.com']);
});

it('accepts a public address literal without a lookup', function () {
    $resolver = tableResolver([]);

    $pinned = (new RelayAddressGuard($resolver))->pin('wss://[2606:4700::1]');

    expect([$pinned->url, $pinned->ip, $pinned->port, $pinned->pathAndQuery, $pinned->isIpv6()])
        ->toBe(['wss://[2606:4700::1]/', '2606:4700::1', 443, '/', true])
        ->and($resolver->asked)->toBe([]);
});
