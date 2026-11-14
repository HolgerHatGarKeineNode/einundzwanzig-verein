<?php

namespace App\Services\Nostr;

/**
 * What one relay answered to one published event (NIP-01 `OK` message), or
 * why there was no answer.
 *
 * `accepted` stays for callers that only ask "does the relay have it?";
 * `status` tells the other three outcomes apart.
 */
final readonly class RelayPublishResult
{
    public bool $accepted;

    private function __construct(
        public RelayPublishStatus $status,
        public string $message,
    ) {
        $this->accepted = $status === RelayPublishStatus::Accepted;
    }

    public static function accepted(string $message = ''): self
    {
        return new self(RelayPublishStatus::Accepted, $message);
    }

    public static function rejected(string $message): self
    {
        return new self(RelayPublishStatus::Rejected, $message);
    }

    public static function failed(string $message): self
    {
        return new self(RelayPublishStatus::Failed, $message);
    }

    public static function refused(string $message): self
    {
        return new self(RelayPublishStatus::Refused, $message);
    }
}
