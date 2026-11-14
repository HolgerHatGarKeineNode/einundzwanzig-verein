<?php

namespace App\Services\Nostr;

/**
 * What one relay answered to one published event (NIP-01 `OK` message).
 */
final readonly class RelayPublishResult
{
    private function __construct(
        public bool $accepted,
        public string $message,
    ) {}

    public static function accepted(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function rejected(string $message): self
    {
        return new self(false, $message);
    }
}
