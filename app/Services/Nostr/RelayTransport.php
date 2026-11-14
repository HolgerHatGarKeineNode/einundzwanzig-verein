<?php

namespace App\Services\Nostr;

/**
 * The one seam through which application code talks to a Nostr relay.
 *
 * Code that publishes or reads over a relay depends on this interface instead
 * of opening a WebSocket itself. That is what makes it testable: a test binds
 * a recording fake, every other test runs with a default that refuses, and
 * `tests/Feature/RelayUsageInventoryTest.php` keeps the list of files that
 * still reach a relay directly from growing.
 *
 * One relay per call on purpose. Fan-out, retries and "which relays answered"
 * are decisions of the caller, and a caller can only make them if each relay's
 * answer comes back on its own.
 */
interface RelayTransport
{
    /**
     * Send one signed event to one relay and return that relay's answer.
     *
     * A relay that answers `OK false` is a result, not an exception. A relay
     * that cannot be reached, or that answers nothing usable, is an exception.
     *
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}  $event
     */
    public function publish(string $relayUrl, array $event): RelayPublishResult;

    /**
     * Ask one relay for the stored events matching one NIP-01 filter.
     *
     * @param  array<string, mixed>  $filter
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $relayUrl, array $filter): array;
}
