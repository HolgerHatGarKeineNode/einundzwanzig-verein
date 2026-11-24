<?php

use App\Services\Nostr\RelayTransport;

/*
 * NOT part of the suite, and red by design.
 *
 * RelayStrayConnectionTest runs this file in a child process to see the check
 * after each test do its work: the test body below is green on its own — it
 * swallows the refusal the way a catch-all in application code does — and must fail anyway.
 * The file name does not end in "Test.php", so no suite collects it; it sits
 * under tests/Feature so the hooks from tests/Pest.php apply to it.
 */

it('swallows the refusal of a relay nobody faked', function () {
    try {
        app(RelayTransport::class)->publish('wss://relay.example/', ['kind' => 1]);
    } catch (Throwable) {
        // What a catch-all in application code does with the refusal.
    }

    expect(true)->toBeTrue();
});
