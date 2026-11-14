<?php

use Symfony\Component\Finder\Finder;

/*
 * The complete list of files that reach a Nostr relay without going through
 * App\Services\Nostr\RelayTransport.
 *
 * New relay code depends on the seam; these files predate it and stay as they
 * are. The list is compared in both directions: a file that starts dialling a
 * relay itself turns this red, and so does an entry that no longer does —
 * otherwise the list would rot into a set of free passes.
 *
 * A text scan rather than an arch() expectation, because the one call site
 * nobody had on the list lives where arch() does not look: a Volt component
 * in resources/views. The einundzwanzig/group package is scanned as well; it
 * is this association's own code and dials relays from two classes.
 *
 * What the scan cannot see is a call site assembled at runtime (a class name
 * in a string, a call through a container alias). The socket guard in
 * RelayStrayConnectionTest covers that side inside the test process.
 *
 * Mutation M0d: add `new \swentel\nostr\Relay\Relay(...)` to any other class
 * under app/ → red.
 */

it('lets no file but the listed ones open a relay connection of its own', function () {
    $signs = [
        'vendor relay' => '/\bRelay\\\\(Relay|RelaySet)\b/',
        'vendor request' => '/\bRequest\\\\Request\b/',
        'vendor relay list lookup' => '/\b(DmRelaysList|RelayListMetadata)\b/',
        'vendor NIP-17 sender' => '/\bNip17\\\\DirectMessage\b/',
        'websocket client' => '/\bWebSocket\\\\Client\b|\bPhrity\\\\/',
        'raw socket' => '/\b(stream_socket_client|fsockopen|pfsockopen)\s*\(/',
    ];

    $files = Finder::create()
        ->files()
        ->name('*.php')
        ->in(array_map(base_path(...), [
            'app',
            'bootstrap',
            'config',
            'database',
            'resources/views',
            'routes',
            'vendor/einundzwanzig/group/src',
        ]))
        ->notPath('cache');

    $scanned = 0;
    $found = [];

    foreach ($files as $file) {
        $scanned++;
        $source = $file->getContents();

        foreach ($signs as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());

                continue 2;
            }
        }
    }

    sort($found);

    // A scan that silently covered nothing would agree with any list.
    expect($scanned)->toBeGreaterThan(250)
        ->and($found)->toBe([
            'app/Console/Commands/Nostr/FetchEvents.php',
            'app/Jobs/PublishPaymentEventToNostr.php',
            'app/Traits/NostrFetcherTrait.php',
            'resources/views/livewire/association/profile.blade.php',
            'vendor/einundzwanzig/group/src/Nostr/ProfileCache.php',
            'vendor/einundzwanzig/group/src/Nostr/SpaceCache.php',
        ]);
});
