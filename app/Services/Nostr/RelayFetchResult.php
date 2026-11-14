<?php

namespace App\Services\Nostr;

/**
 * The events one relay returned for one filter, and whether that was all.
 *
 * `complete` is true only when the relay said EOSE. An empty, incomplete
 * result means "this relay did not answer", not "there is nothing" — the
 * difference decides whether a member has no inbox or merely an unreachable
 * relay.
 */
final readonly class RelayFetchResult
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function __construct(
        public array $events,
        public bool $complete,
        public string $message,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function complete(array $events): self
    {
        return new self(array_values($events), true, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function incomplete(array $events, string $message): self
    {
        return new self(array_values($events), false, $message);
    }
}
